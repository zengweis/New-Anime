#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 对象存储运维命令行。
 *
 *   php bin/s3_upload.php test              连通性自检（读 + 写探针）
 *   php bin/s3_upload.php check-public      公开直链是否真的能匿名取到字节
 *   php bin/s3_upload.php stat              队列与用量概况（含锁文件可用性）
 *   php bin/s3_upload.php pending [n]       列出待上传的集
 *   php bin/s3_upload.php push [n]          上传待传队列（默认按设置里的并发上限）
 *   php bin/s3_upload.php push --id=123     指定单集上传（--force 强制重传）
 *   php bin/s3_upload.php purge [n|--all]   把「已上传但本地还在」的集补删掉，释放磁盘
 *   php bin/s3_upload.php ls [prefix]       拉取远程文件列表
 *   php bin/s3_upload.php retry             把失败条目重置为可重试
 */

if (PHP_SAPI !== 'cli') {
    exit("仅限命令行运行\n");
}

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Database;
use App\Core\S3;
use App\Core\Setting;
use App\Service\S3Uploader;

date_default_timezone_set((string) cfg('timezone', 'Asia/Shanghai'));

$argvList = $argv;
array_shift($argvList);
$cmd = (string) (array_shift($argvList) ?? 'stat');

/** 收集 --key=value 形式的参数 */
$flags = [];
$args  = [];
foreach ($argvList as $a) {
    if (str_starts_with($a, '--')) {
        [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, '1');
        $flags[$k] = $v;
    } else {
        $args[] = $a;
    }
}

function line(string $s = ''): void
{
    echo $s . "\n";
}

function human(int $b): string
{
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $v = (float) $b;
    while ($v >= 1024 && $i < count($u) - 1) {
        $v /= 1024;
        $i++;
    }
    return ($v >= 100 || $i === 0 ? number_format($v, 0) : number_format($v, 1)) . $u[$i];
}

if ($cmd !== 'test' && !S3::enabled()) {
    line('对象存储未启用或配置不完整：' . S3::configHint());
    exit(1);
}

switch ($cmd) {
    // ---------------------------------------------------------------- 自检
    case 'test':
        $s3 = S3::fromSettings();
        if ($s3 === null) {
            line('配置不完整：' . S3::configHint());
            exit(1);
        }
        line('endpoint : ' . (string) Setting::get('s3_endpoint'));
        line('bucket   : ' . (string) Setting::get('s3_bucket'));
        line('region   : ' . (string) Setting::get('s3_region', 'auto'));
        line('prefix   : ' . S3::prefix());
        line('公开基址 : ' . (S3::publicBase() ?: '(未配置)'));
        line('取址模式 : ' . (string) Setting::get('s3_url_mode', 'public'));
        line('');
        $r = $s3->test(true);
        foreach ($r['detail'] as $k => $v) {
            line(sprintf('  %-16s %s', $k, is_scalar($v) ? (string) $v : json_encode($v)));
        }
        line('');
        line(($r['ok'] ? '[OK] ' : '[FAIL] ') . $r['msg']);
        exit($r['ok'] ? 0 : 1);

    // ---------------------------------------------------------------- 概况
    case 'stat':
        $s = S3Uploader::stats();
        line('已上传        : ' . $s['uploaded']);
        line('  其中已删本地: ' . $s['purged']);
        line('  保留本地    : ' . $s['local_kept']);
        line('仅远程（补档）: ' . $s['remote_only']);
        line('待上传        : ' . $s['queued'] . '（含尚未探测/remux/抽字幕的，门槛满足后自动进入上传）');
        line('失败          : ' . $s['failed']);
        line('远端占用      : ' . human($s['remote_bytes']));
        line('本地剩余空间  : ' . ($s['disk_free'] !== null ? human($s['disk_free']) : '未知'));
        line('上传并发上限  : ' . Setting::get('s3_upload_limit', '2'));
        line('上传后删本地  : ' . (Setting::get('s3_delete_local', '1') === '1' ? '是' : '否'));

        // 锁文件可用性：这里必须自检，因为「锁被别的用户建过导致拿不到」会让
        // 上传/抽字幕任务**静默停摆**，表现是任务不跑而不是报错，极难排查。
        line('');
        line('任务锁文件    :');
        $bad = 0;
        foreach ([
            '字幕抽取' => \App\Service\SubtitleService::root() . '/.extract.lock',
            'remux'    => \App\Service\RemuxService::root() . '/.remux.lock',
            '上传'     => \App\Service\RemuxService::root() . '/.s3upload.lock',
        ] as $name => $path) {
            $d = \App\Core\FileLock::diagnose($path);
            if (!$d['ok']) {
                $bad++;
            }
            line(sprintf('  %-10s %-56s %s', $name, $d['path'], $d['note']));
        }
        if ($bad > 0) {
            line('');
            line('  !! 有 ' . $bad . ' 个锁文件不可用，对应任务会静默不执行。');
            line('     修复：chown -R www-data:www-data ' . \App\Service\RemuxService::downloadRoot()
                . ' ' . \App\Service\SubtitleService::root());
            exit(1);
        }
        break;

    // ---------------------------------------------------------------- 队列
    case 'pending':
        $n = (int) ($args[0] ?? 20);
        $rows = S3Uploader::pending($n);
        if (!$rows) {
            line('没有待上传的剧集');
            break;
        }
        foreach ($rows as $r) {
            line(sprintf(
                '  #%-6d 番%-5d 第%-5s %-9s status=%d attempts=%d  %s',
                $r['id'],
                $r['anime_id'],
                rtrim(rtrim((string) $r['ep_no'], '0'), '.'),
                ($r['remux_path'] ? 'MP4' : '原始'),
                $r['s3_key'] ? 1 : 0,
                $r['s3_attempts'],
                mb_substr((string) $r['title'], 0, 60)
            ));
        }
        line('');
        line(count($rows) . ' 条');
        break;

    // ---------------------------------------------------------------- 上传
    case 'push':
        try {
            $up = new S3Uploader();
        } catch (Throwable $e) {
            line('初始化失败：' . $e->getMessage());
            exit(1);
        }

        $ids = [];
        if (isset($flags['id'])) {
            $ids = [(int) $flags['id']];
        } else {
            $ids = array_map(static fn(array $r): int => (int) $r['id'], S3Uploader::pending((int) ($args[0] ?? 2)));
        }
        if (!$ids) {
            line('没有待上传的剧集');
            break;
        }

        $force = isset($flags['force']);
        $okCount = 0;
        $failCount = 0;
        $purgeWarn = 0;
        $total = 0;
        foreach ($ids as $id) {
            if ($force) {
                Database::run('UPDATE `episode` SET `s3_attempts` = 0 WHERE `id` = :id', ['id' => $id]);
            }
            $t0 = microtime(true);
            $r = $up->upload($id, $force);
            $cost = round(microtime(true) - $t0, 1);

            if ($r['busy']) {
                line('  另一路上传正在进行，停止');
                break;
            }
            if ($r['ok']) {
                $okCount++;
                $total += $r['bytes'];
                $tail = $r['purged']
                    ? ' · 已删本地'
                    : (($r['purge_error'] ?? null) !== null ? ' · !! 本地未删除' : '');
                line(sprintf(
                    '  [OK] #%d %s %s%s (%ss)',
                    $id,
                    $r['reason'] ?: '上传完成',
                    human($r['bytes']),
                    $tail,
                    $cost
                ));
                if (!$r['purged'] && ($r['purge_error'] ?? null) !== null) {
                    line('        ! ' . $r['purge_error']);
                    $purgeWarn++;
                }
            } else {
                $failCount++;
                line(sprintf('  [FAIL] #%d %s (%ss)', $id, $r['error'] ?: $r['reason'], $cost));
            }
        }
        line('');
        line(sprintf('完成 %d 失败 %d，共上传 %s', $okCount, $failCount, human($total)));
        if ($purgeWarn > 0) {
            line('');
            line('!! 有 ' . $purgeWarn . ' 集的本地文件没能删除，磁盘并未释放。');
            line('   典型原因：下载目录由 qBittorrent 以另一个用户写入，而任务以 www-data 运行。');
            line('   修复：chown -R www-data:www-data <下载根目录> 并给目录加 setgid + 组写权限');
            line('        （详见 README；锁文件同理）');
        }
        exit($failCount > 0 ? 1 : 0);

    // ---------------------------------------------------------------- 远程列表
    case 'ls':
        try {
            $up = new S3Uploader();
        } catch (Throwable $e) {
            line('初始化失败：' . $e->getMessage());
            exit(1);
        }
        $prefix = (string) ($args[0] ?? '');
        $r = $up->remoteList($prefix, null, 200);
        if (!$r['ok']) {
            line('拉取失败：' . $r['error']);
            exit(1);
        }
        foreach ($r['objects'] as $o) {
            line(sprintf(
                '  %-10s %-20s %s%s',
                human((int) $o['size']),
                substr((string) $o['modified'], 0, 19),
                $o['key'],
                $o['used'] ? '   [已被 #' . $o['used']['episode_id'] . ' 引用]' : ''
            ));
        }
        line('');
        line(count($r['objects']) . ' 个对象' . ($r['truncated'] ? '（还有更多，用 --cursor 续取）' : ''));
        break;

    // ---------------------------------------------------------------- 重置失败
    case 'retry':
        $n = Database::run(
            'UPDATE `episode` SET `s3_status` = 0, `s3_attempts` = 0, `s3_err` = NULL
              WHERE `s3_status` = 2'
        )->rowCount();
        line("已重置 {$n} 条失败记录，可重新上传");
        break;

    // ---------------------------------------------------------------- 公开直链
    case 'check-public':
        $s3 = S3::fromSettings();
        if ($s3 === null) {
            line('配置不完整：' . S3::configHint());
            exit(1);
        }
        $base = S3::publicBase();
        line('公开基址 : ' . ($base !== '' ? $base : '(未配置)'));
        line('取址模式 : ' . (string) Setting::get('s3_url_mode', 'public'));
        line('');

        $key    = S3::prefix() . '/.public-probe-' . bin2hex(random_bytes(6)) . '.txt';
        $expect = 'anime-new public probe ' . date('c');
        $tmp    = sys_get_temp_dir() . '/s3public-' . getmypid() . '.txt';
        file_put_contents($tmp, $expect);

        $p = $s3->putFile($key, $tmp, 'text/plain');
        @unlink($tmp);
        if (!$p['ok']) {
            line('上传探针失败：' . $p['error']);
            exit(1);
        }
        line('已上传探针：' . $key);

        $url = $s3->publicUrl($key);
        line('公开直链  : ' . $url);
        if ($url === '') {
            $s3->delete($key);
            line('');
            line('[FAIL] 公开基址为空，无法生成直链。请在设置里填「公开访问基址」');
            exit(1);
        }

        // 不带任何凭据去取，模拟浏览器
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        $s3->delete($key);
        line('探针已清理');

        line('');
        line('HTTP ' . $code . ($err !== '' ? '  错误：' . $err : ''));
        $same = hash('sha256', $body) === hash('sha256', $expect);
        if ($code === 200 && $same) {
            line('[OK] 公开直链可用：匿名请求能取到正确字节');
            exit(0);
        }

        line('[FAIL] 公开直链不可用（HTTP ' . $code . '，内容' . ($same ? '一致' : '不一致') . '）');
        line('');
        line('常见原因：');
        line('  1. 桶还没开启公开访问 —— R2 控制台 → 该桶 → Settings → Public access');
        line('     · 开 r2.dev 子域：得到 https://pub-xxxx.r2.dev，填到「公开访问基址」');
        line('     · 或绑自定义域（如 r2.acgn.help），DNS 要指向 Cloudflare 且已生效');
        line('  2. 自定义域刚绑定，DNS 还没生效（等几分钟）');
        line('  3. 公开基址里多带了桶名（自定义域已绑定到桶，URL 里不再需要桶名）');
        exit(1);

    // ---------------------------------------------------------------- 补删本地
    case 'purge':
        // 用途：上传成功但本地文件没删掉的集（多为权限问题修好之前的历史遗留），
        // 现在把它们清掉，把磁盘真正释放出来。
        try {
            $up = new S3Uploader();
        } catch (Throwable $e) {
            line('初始化失败：' . $e->getMessage());
            exit(1);
        }
        $limit = isset($flags['all']) ? 100000 : (int) ($args[0] ?? 50);
        // 刻意不按 s3_purged_at 过滤：早期版本在 unlink 失败时照样写了这个时间戳，
        // 「时间戳说清了、文件还在」的历史欠账也要能被这条命令兜住，所以以文件系统为准。
        $rows  = Database::all(
            'SELECT `id`,`ep_no`,`file_path`,`remux_path`,`s3_purged_at` FROM `episode`
              WHERE `s3_status` = 1
                AND (`file_path` <> \'\' OR `remux_path` IS NOT NULL AND `remux_path` <> \'\')
              ORDER BY `id` ASC
              LIMIT ' . max(1, $limit)
        );

        // 先筛掉「本地确实什么都不剩」的，避免刷一堆无意义输出
        $todo = [];
        foreach ($rows as $r) {
            $size = 0;
            foreach ([$r['remux_path'], $r['file_path']] as $p) {
                $p = (string) $p;
                if ($p !== '' && is_file($p)) {
                    $size += (int) @filesize($p);
                }
            }
            if ($size > 0) {
                $todo[] = ['id' => (int) $r['id'], 'size' => $size, 'claimed' => $r['s3_purged_at'] !== null];
            }
        }
        if (!$todo) {
            line('没有「已上传但本地还在」的集，磁盘已经是干净的');
            break;
        }

        line('共 ' . count($todo) . ' 集本地仍有文件：');
        $ok = 0;
        $bad = 0;
        $freed = 0;
        foreach ($todo as $t) {
            $pg = $up->purgeLocal($t['id'], null, true);
            if ($pg['ok']) {
                $ok++;
                $freed += $t['size'];
                line(sprintf(
                    '  [OK] #%-6d 释放 %s%s',
                    $t['id'],
                    human($t['size']),
                    $t['claimed'] ? '（该集此前被误标为已清理）' : ''
                ));
            } else {
                $bad++;
                line(sprintf('  [FAIL] #%-6d %s', $t['id'], $pg['error']));
            }
        }
        line('');
        line(sprintf('清理完成 %d 失败 %d，释放约 %s', $ok, $bad, human($freed)));
        if ($bad > 0) {
            line('');
            line('仍有失败说明运行用户对下载目录没有写权限。修复：');
            line('  chown -R www-data:www-data <下载根目录>');
            line('  find <下载根目录> -type d -exec chmod 2775 {} \\;');
            line('  并给 qBittorrent / 任务进程设置 UMask=0002（systemd 单元里加一行）');
            exit(1);
        }
        break;

    default:
        line('未知命令：' . $cmd);
        line('可用：test | check-public | stat | pending | push | purge | ls | retry');
        exit(1);
}
