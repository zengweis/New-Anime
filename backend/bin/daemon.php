#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 常驻调度进程：一个进程跑完抓取 / 同步 / 发信三件事。
 * 不想用 cron 就用它，交给 systemd 托管。
 *
 *   php bin/daemon.php
 *   php bin/daemon.php --interval-fetch=1800 --interval-sync=120 --interval-mail=60
 */

if (PHP_SAPI !== 'cli') {
    exit("仅限命令行运行\n");
}

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Database;
use App\Core\S3;
use App\Core\Setting;
use App\Service\FetchService;
use App\Service\MailService;
use App\Service\MediaProbe;
use App\Service\RemuxService;
use App\Service\S3Uploader;
use App\Service\SubtitleService;
use App\Service\SyncService;

date_default_timezone_set((string) cfg('timezone', 'Asia/Shanghai'));

$opt    = getopt('', ['interval-fetch::', 'interval-sync::', 'interval-mail::', 'interval-subs::', 'interval-remux::', 'interval-upload::', 'interval-pause::', 'interval-ocr::', 'fetch-limit::']);

// 抓取间隔：命令行显式指定就固定用它；否则**每轮从设置里重新读**，
// 这样在后台改「每隔多少分钟抓一次」立即生效，不必重启常驻进程。
$fetchOverride = isset($opt['interval-fetch']) ? max(60, (int) $opt['interval-fetch']) : null;
$iFetch = $fetchOverride ?? max(60, Setting::int('fetch_interval_min', 30) * 60);

$iSync  = max(20, (int) ($opt['interval-sync'] ?? 120));
$iMail  = max(20, (int) ($opt['interval-mail'] ?? 60));
$iSubs  = max(20, (int) ($opt['interval-subs'] ?? 120));
$iRemux = max(20, (int) ($opt['interval-remux'] ?? 120));
$iUp    = max(20, (int) ($opt['interval-upload'] ?? 120));
$iPause = max(20, (int) ($opt['interval-pause'] ?? 60));
$iOcr   = max(30, (int) ($opt['interval-ocr'] ?? 60));
$limit  = max(1, (int) ($opt['fetch-limit'] ?? 300));

$running = true;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = static function (int $sig) use (&$running): void {
        $running = false;
        say("收到信号 {$sig}，将在当前任务结束后退出");
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

say(sprintf(
    '守护进程启动 pid=%d · 抓取 %s · 同步 %ds · 发信 %ds · 字幕 %ds · remux %ds · 上传 %ds · 暂停做种 %ds · 位图OCR %ds',
    getmypid(),
    $fetchOverride !== null ? $iFetch . 's' : $iFetch . 's(动态，读设置)',
    $iSync,
    $iMail,
    $iSubs,
    $iRemux,
    $iUp,
    $iPause,
    $iOcr
));

/** 包一层异常保护，单次任务失败不影响主循环 */
function task(string $name, callable $fn): void
{
    $t0 = microtime(true);
    try {
        $fn();
    } catch (Throwable $e) {
        say("[{$name}] 异常: " . $e->getMessage(), true);
    }
    $cost = (int) ((microtime(true) - $t0) * 1000);
    if ($cost > 3000) {
        say("[{$name}] 耗时 {$cost} ms");
    }
}

function say(string $msg, bool $err = false): void
{
    $line = sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $msg);
    if ($err) {
        fwrite(STDERR, $line);
    } else {
        echo $line;
    }
    if (function_exists('fflush')) {
        @fflush($err ? STDERR : STDOUT);
    }
}

$last = ['mail' => 0, 'sync' => 0, 'fetch' => 0, 'subs' => 0, 'remux' => 0, 'upload' => 0, 'pause' => 0, 'ocr' => 0];

while ($running) {
    $now = time();

    // ---- 每轮重新读一遍配置 ----
    // Setting 有进程内静态缓存，默认只查一次库；常驻进程里就会永远用启动那一刻的值，
    // 于是「改了设置必须重启」成了隐性规则。这里强制刷新，让后台改的设置立即生效
    // （抓取间隔、上传并发、S3 开关、删本地开关等等都受益）。
    // 一次 SELECT ~40 行的开销，相比 3 秒一轮的循环可以忽略。
    Setting::all(true);

    // 抓取间隔改为每轮按当前设置计算
    if ($fetchOverride === null) {
        $newFetch = max(60, Setting::int('fetch_interval_min', 30) * 60);
        if ($newFetch !== $iFetch) {
            say(sprintf(
                '抓取间隔已动态调整：%d 分钟 → %d 分钟（%ds → %ds），无需重启',
                (int) ($iFetch / 60),
                (int) ($newFetch / 60),
                $iFetch,
                $newFetch
            ));
            $iFetch = $newFetch;
        }
    }

    // 位图字幕（PGS）的后台处理：只负责「起进程」和「回收结果」，绝不在这里等它跑完。
    //   渲染图片  一轨 1000 个 display set 约 100 秒 CPU
    //   OCR 文本  一轨 600+ 条字幕逐条 tesseract，十几到几十分钟
    // 同步做都会把 remux / 上传 / 删本地 一起堵死，所以常驻进程只做调度。
    // 每轮最多起 1 个（2 核机器）。具体走哪种形态由 subs_bitmap_mode 决定。
    if ($now - $last['ocr'] >= $iOcr) {
        task('bitmap', static function (): void {
            $r = SubtitleService::harvestBitmap(1);
            if ($r['spawned'] > 0) {
                say("位图字幕已排入后台处理（{$r['spawned']} 项）");
            }
            if ($r['imported'] > 0) {
                say("位图字幕产物已登记 {$r['imported']} 轨");
            }
        });
        $last['ocr'] = time();
    }

    // 下载完成后暂停做种：省上行流量。qB 没有「完成」回调，只能轮询；
    // 60 秒一轮，多传最多一分钟。关闭该设置时整段跳过，不发任何请求。
    if ($now - $last['pause'] >= $iPause) {
        task('pause', static function (): void {
            if (!Setting::bool('qb_pause_after_done', false)) {
                return;
            }
            $r = SyncService::pauseFinished();
            if ($r['paused'] > 0) {
                say("已暂停 {$r['paused']} 个已完成任务的做种（省流量）");
            }
            if ($r['failed'] > 0) {
                say("  ! 暂停做种失败 {$r['failed']} 个", true);
            }
        });
        $last['pause'] = time();
    }

    // 上传到对象存储：排在 remux 之后，传的是浏览器原生可播的 MP4。
    // 上传校验通过后按设置删除本地文件（省磁盘）；未开启 S3 时整段跳过，零开销。
    // 大文件上传（分片 + 带宽）耗时以分钟计，所以限流到每轮 1~2 集。
    if ($now - $last['upload'] >= $iUp) {
        task('upload', static function (): void {
            if (!S3::enabled() || Setting::get('s3_auto_upload', '1') !== '1') {
                return;
            }
            try {
                $up = new S3Uploader();
            } catch (\Throwable $e) {
                say('  ! 上传器初始化失败：' . $e->getMessage(), true);
                return;
            }

            $limit = max(1, (int) Setting::get('s3_upload_limit', 2));
            $pend  = S3Uploader::pending($limit);
            if (!$pend) {
                return;
            }

            $done = 0;
            $fail = 0;
            $bytes = 0;
            foreach ($pend as $r) {
                $res = $up->upload((int) $r['id']);
                if ($res['busy']) {
                    break; // 手动上传正在跑，下一轮再来
                }
                if ($res['ok']) {
                    $done++;
                    $bytes += $res['bytes'];
                } elseif ($res['reason'] !== 'waiting_remux' && $res['reason'] !== 'already_uploaded') {
                    $fail++;
                    if ($res['error']) {
                        say('  ! 上传 #' . $r['id'] . ' ' . $res['error'], true);
                    }
                }
            }
            if ($done || $fail) {
                say(sprintf(
                    '上传 完成 %d（%s）失败 %d',
                    $done,
                    $bytes > 0 ? round($bytes / 1048576, 1) . 'MB' : '0MB',
                    $fail
                ));
            }
        });
        $last['upload'] = time();
    }

    // remux：下载完就把 mkv 一次性转成 mp4（-c copy），播放时是原生直通。
    // 单集约 4 秒纯 I/O，单线程低优先级，积压也很快清空。
    if ($now - $last['remux'] >= $iRemux) {
        task('remux', static function (): void {
            $pend = RemuxService::pending(3);
            if (!$pend) {
                return;
            }
            $done = 0;
            $fail = 0;
            foreach ($pend as $r) {
                $res = RemuxService::remux((int) $r['id']);
                if ($res['busy']) {
                    break;
                }
                if ($res['ok']) {
                    $done++;
                } else {
                    $fail++;
                    if ($res['error']) {
                        say('  ! remux #' . $r['id'] . ' ' . $res['error'], true);
                    }
                }
            }
            if ($done || $fail) {
                say(sprintf('remux 完成 %d 失败 %d', $done, $fail));
            }
        });
        $last['remux'] = time();
    }

    // 字幕预抽：下载完就抽好，用户点播放时是现成的，不必等。
    // 单集实测约 0.5 秒（只解封装、不解视频码），所以即使积压也很快清空。
    if ($now - $last['subs'] >= $iSubs) {
        task('subs', static function (): void {
            // 先补探测：字幕轨数量得先由 ffprobe 读出来才知道有没有
            $unprobed = Database::all(
                'SELECT `id` FROM `episode`
                 WHERE `probe_at` IS NULL AND `file_path` <> \'\'
                 ORDER BY `id` DESC LIMIT 5'
            );
            foreach ($unprobed as $r) {
                MediaProbe::probeEpisode((int) $r['id']);
            }

            $pend = SubtitleService::pending(5);
            if (!$pend) {
                return;
            }

            $done = 0;
            $tracks = 0;
            $fail = 0;
            foreach ($pend as $r) {
                $res = SubtitleService::extract((int) $r['id']);
                if ($res['busy']) {
                    break; // 有别的抽取在跑，下一轮再来
                }
                if ($res['reason'] === 'extracted') {
                    $done++;
                    $tracks += count($res['tracks']);
                } elseif (!$res['ok'] && $res['reason'] !== 'no_subtitle_stream') {
                    $fail++;
                }
            }
            say(sprintf('字幕 抽取 %d 集 / %d 轨，失败 %d', $done, $tracks, $fail));
        });
        $last['subs'] = time();
    }

    if ($now - $last['mail'] >= $iMail) {
        task('mail', static function (): void {
            $r = MailService::processQueue(50);
            if ($r['sent'] || $r['failed']) {
                say("发信 成功 {$r['sent']} 失败 {$r['failed']} 剩余 {$r['remain']}");
            }
        });
        $last['mail'] = time();
    }

    if ($now - $last['sync'] >= $iSync) {
        task('sync', static function (): void {
            $r = (new SyncService())->run();
            if ($r['episodes'] > 0 || $r['errors']) {
                say("同步 新入库 {$r['episodes']} 集（扫描 {$r['torrents']} 任务）");
                foreach ($r['errors'] as $e) {
                    say('  ! ' . $e, true);
                }
            }
        });
        $last['sync'] = time();
    }

    if ($now - $last['fetch'] >= $iFetch) {
        task('fetch', static function () use ($limit): void {
            $r = (new FetchService())->fetchAndDownload(null, $limit);
            $f = $r['fetch'];
            $a = $r['auto'];
            say(sprintf(
                '抓取 源%d 共%d 新增%d 过滤%d · 加种%d 跳过%d',
                $f['feeds'],
                $f['fetched'],
                $f['new'],
                $f['filtered'],
                $a['added'] ?? 0,
                $a['blocked'] ?? 0
            ));
        });
        $last['fetch'] = time();
    }

    sleep(3);
}

say('守护进程已退出');
exit(0);
