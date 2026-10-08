#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 位图字幕（PGS/DVD）运维工具。
 *
 * 位图字幕是图像，网页不能直接显示，有两条出路，本工具都管：
 *   图片叠加（image）  按 PGS 自带的时机/位置/调色板渲成透明图片，前端叠在视频上。
 *                      100% 保真、一轨约 100 秒 CPU。
 *   OCR 文本（ocr）    逐条 tesseract 成文本，可选可搜索，但一轨十几分钟且特效处会出错字。
 * 走哪条由设置 subs_bitmap_mode 决定（image / ocr / both / off）。
 *
 *   php bin/ocr_subs.php status                看每条 sup 的图片与 OCR 状态
 *   php bin/ocr_subs.php env                   两条路的环境自检
 *   php bin/ocr_subs.php run [n] [--force]     起 n 个后台任务并回收已完成产物（n=0 只回收）
 *   php bin/ocr_subs.php wait [--timeout=3600] 轮询到没有待处理项为止
 *   php bin/ocr_subs.php render <episodeId>    前台渲染某集的字幕图片（看得见进度）
 *   php bin/ocr_subs.php oneshot <episodeId>   前台 OCR 某集（十几分钟，只在排查时用）
 *
 * 为什么必须后台：两条路都会吃满 CPU 若干分钟。常驻进程主循环是串行的，
 * 同步做会把 remux / 上传 / 删本地 一起堵住，所以生产路径只「起进程 + 回收」。
 */

if (PHP_SAPI !== 'cli') {
    exit("仅限命令行运行\n");
}

require __DIR__ . '/../src/bootstrap.php';

use App\Service\RemuxService;
use App\Service\SubtitleImage;
use App\Service\SubtitleOcr;
use App\Service\SubtitleService;

$argvList = $argv;
array_shift($argvList);
$cmd = (string) (array_shift($argvList) ?? 'status');

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
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $v = (float) $b;
    while ($v >= 1024 && $i < count($u) - 1) {
        $v /= 1024;
        $i++;
    }
    return ($v >= 100 || $i === 0 ? number_format($v, 0) : number_format($v, 1)) . $u[$i];
}

/** 从 sup 文件名里拿流序号（s3.zh-Hans.sup → 3） */
function streamIndexOf(string $sup): int
{
    if (preg_match('/^s(\d+)\./', basename($sup), $m)) {
        return (int) $m[1];
    }
    return 0;
}

/**
 * 扫描所有剧集字幕目录，汇总每条 sup 的「图片」与「OCR」两路状态。
 *
 * @return array<int,array<string,mixed>>
 */
function survey(): array
{
    $mode = SubtitleService::bitmapMode();
    $out  = [];
    foreach (glob(SubtitleService::root() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $ep = (int) basename($dir);
        if ($ep <= 0) {
            continue;
        }
        foreach (glob($dir . '/*.sup') ?: [] as $sup) {
            $idx = streamIndexOf($sup);
            $row = [
                'ep'    => $ep,
                'sup'   => basename($sup),
                'path'  => $sup,
                'dir'   => $dir,
                'idx'   => $idx,
                'bytes' => (int) @filesize($sup),
                'image' => '—',
                'ocr'   => '—',
            ];

            // ---- 图片叠加层 ----
            if (SubtitleService::modeWantsImage($mode)) {
                if (SubtitleImage::ready($dir, $idx)) {
                    $m = SubtitleImage::manifest($dir, $idx);
                    $bytes = 0;
                    foreach (glob(SubtitleImage::dirFor($dir, $idx) . '/*') ?: [] as $f) {
                        $bytes += (int) @filesize($f);
                    }
                    $row['image'] = sprintf('已就绪 %d 屏 / %s', (int) ($m['count'] ?? 0), human($bytes));
                } elseif (SubtitleImage::running($dir, $idx)) {
                    $row['image'] = '渲染中';
                } elseif (SubtitleImage::exhausted($dir, $idx)) {
                    $row['image'] = '已放弃';
                } else {
                    $row['image'] = '排队';
                }
            }

            // ---- OCR 文本 ----
            if (SubtitleService::modeWantsOcr($mode)) {
                $srt = SubtitleOcr::srtPathFor($sup);
                if (is_file($srt)) {
                    $cues       = (int) @shell_exec('grep -c -- " --> " ' . escapeshellarg($srt) . ' 2>/dev/null');
                    $row['ocr'] = '已完成 ' . $cues . ' 条';
                } elseif (SubtitleOcr::running($sup)) {
                    $row['ocr'] = '识别中';
                } elseif (SubtitleOcr::exhausted($sup)) {
                    $row['ocr'] = '已放弃';
                } else {
                    $row['ocr'] = '排队';
                }
            }

            $out[] = $row;
        }
    }
    return $out;
}

/** 还有没有活儿没干完 */
function pendingCount(array $rows): int
{
    $n = 0;
    foreach ($rows as $r) {
        foreach (['image', 'ocr'] as $k) {
            if (str_contains((string) $r[$k], '排队') || str_contains((string) $r[$k], '中')) {
                $n++;
            }
        }
    }
    return $n;
}

switch ($cmd) {
    // ---------------------------------------------------------------- 环境
    case 'env':
        line('处理模式 : ' . SubtitleService::bitmapMode() . '（subs_bitmap_mode）');
        line('字幕缓存 : ' . SubtitleService::root());
        line('');

        $img = SubtitleImage::available();
        line('[图片叠加]');
        line('  python   : ' . $img['python'] . (is_file($img['python']) ? '（存在）' : '（缺失）'));
        line('  脚本     : ' . $img['script'] . (is_file($img['script']) ? '（存在）' : '（缺失）'));
        line('  结论     : ' . ($img['ok'] ? '可用' : '不可用：' . $img['reason']));
        line('');

        $a = SubtitleOcr::available();
        line('[OCR 文本]');
        line('  pgsrip   : ' . $a['bin'] . (is_file($a['bin']) ? '（存在）' : '（缺失）'));
        line('  tesseract: ' . ($a['tesseract'] !== '' ? $a['tesseract'] : '缺失'));
        line('  语言包   : ' . trim((string) @shell_exec('tesseract --list-langs 2>/dev/null | tail -n +2 | tr "\\n" " "')));
        line('  结论     : ' . ($a['ok'] ? '可用' : '不可用：' . $a['reason']));
        line('');

        line('ffmpeg    : ' . trim((string) @shell_exec(escapeshellcmd(RemuxService::ffmpegBin()) . ' -version 2>/dev/null | head -1')));

        $mode   = SubtitleService::bitmapMode();
        $okImg  = !SubtitleService::modeWantsImage($mode) || $img['ok'];
        $okOcr  = !SubtitleService::modeWantsOcr($mode) || $a['ok'];
        exit($okImg && $okOcr ? 0 : 1);

    // ---------------------------------------------------------------- 状态
    case 'status':
        $rows = survey();
        line('处理模式 : ' . SubtitleService::bitmapMode());
        line('');
        line(sprintf('%-7s %-24s %-22s %-18s %s', '剧集', 'sup', '图片叠加', 'OCR 文本', '体积'));
        foreach ($rows as $r) {
            line(sprintf('%-7d %-24s %-22s %-18s %s', $r['ep'], $r['sup'], $r['image'], $r['ocr'], human($r['bytes'])));
        }
        if (!$rows) {
            line('（没有 .sup，说明这批片源都没有位图字幕）');
        }
        break;

    // ---------------------------------------------------------------- 起进程
    case 'run':
        // n 允许为 0：只回收已完成的产物，不新起进程（验收/排查时用）
        // --force：即使已登记也重新导入（改了清洗规则后重新生成 VTT / 重登记清单）
        $n     = array_key_exists(0, $args) ? max(0, (int) $args[0]) : 1;
        $force = isset($flags['force']);
        $r     = SubtitleService::harvestBitmap($n, $force);
        line(sprintf(
            '扫描 %d 个目录：新起 %d 个任务，回收登记 %d 轨%s',
            $r['dirs'],
            $r['spawned'],
            $r['imported'],
            $force ? '（force）' : ''
        ));
        break;

    // ---------------------------------------------------------------- 等完
    case 'wait':
        $deadline = time() + (int) ($flags['timeout'] ?? 3600);
        $lastMsg  = '';
        while (time() < $deadline) {
            SubtitleService::harvestBitmap(1);
            $pending = pendingCount(survey());
            $msg     = '待处理 ' . $pending . ' 项';
            if ($msg !== $lastMsg) {
                line('[' . date('H:i:s') . '] ' . $msg);
                $lastMsg = $msg;
            }
            if ($pending === 0) {
                line('');
                line('全部处理完成');
                exit(0);
            }
            sleep(15);
        }
        line('超时，仍有待处理项');
        exit(1);

    // ---------------------------------------------------------------- 前台渲染图片
    case 'render':
        $ep = (int) ($args[0] ?? 0);
        if ($ep <= 0) {
            line('用法：php bin/ocr_subs.php render <episodeId> [--format=webp|png] [--quality=90]');
            exit(1);
        }
        $dir  = SubtitleService::root() . '/' . $ep;
        $sups = glob($dir . '/*.sup') ?: [];
        if (!$sups) {
            line("第 {$ep} 集没有 .sup");
            exit(1);
        }
        $img = SubtitleImage::available();
        if (!$img['ok']) {
            line('渲染环境不可用：' . $img['reason']);
            exit(1);
        }
        foreach ($sups as $sup) {
            $idx = streamIndexOf($sup);
            $out = SubtitleImage::dirFor($dir, $idx);
            line(basename($sup) . ' → ' . basename($out));
            if (SubtitleImage::ready($dir, $idx)) {
                line('  已有产物，跳过（要重渲先删掉该目录）');
                continue;
            }
            $fmt = (string) ($flags['format'] ?? 'webp');
            $q   = (int) ($flags['quality'] ?? 90);
            $cmd = sprintf(
                'timeout %d %s %s %s %s --format=%s --quality=%d 2>&1',
                SubtitleImage::TIMEOUT,
                escapeshellcmd($img['python']),
                escapeshellarg($img['script']),
                escapeshellarg($sup),
                escapeshellarg($out),
                escapeshellarg($fmt),
                $q
            );
            $t0  = microtime(true);
            $buf = [];
            $rc  = 0;
            @exec($cmd, $buf, $rc);
            $cost = round(microtime(true) - $t0, 1);
            line('  rc=' . $rc . ' 耗时 ' . $cost . 's ' . trim((string) (end($buf) ?: '')));
        }
        line('');
        line('回收登记：');
        print_r(SubtitleService::harvestBitmap(0));
        break;

    // ---------------------------------------------------------------- 前台 OCR
    case 'oneshot':
        $ep = (int) ($args[0] ?? 0);
        if ($ep <= 0) {
            line('用法：php bin/ocr_subs.php oneshot <episodeId>');
            exit(1);
        }
        $dir  = SubtitleService::root() . '/' . $ep;
        $sups = glob($dir . '/*.sup') ?: [];
        if (!$sups) {
            line("第 {$ep} 集没有 .sup（位图字幕可能在删本地时就已经丢了）");
            exit(1);
        }
        line('找到 ' . count($sups) . ' 个 sup：');
        foreach ($sups as $sup) {
            $lang = SubtitleOcr::langFromFilename($sup);
            $srt  = SubtitleOcr::srtPathFor($sup);
            line('  ' . basename($sup) . '（语言 ' . $lang . '）');
            if (is_file($srt)) {
                line('    已有 srt，跳过');
                continue;
            }
            line('    开始识别（前台，可能十几分钟）…');
            $t0 = microtime(true);
            $cmd = sprintf(
                'export TESSDATA_PREFIX=%s; timeout %d %s rip %s -l %s --no-tessdata-download -f -w 2 2>&1',
                escapeshellarg('/usr/share/tesseract-ocr/5/tessdata'),
                SubtitleOcr::TIMEOUT,
                escapeshellcmd(SubtitleOcr::bin()),
                escapeshellarg($sup),
                escapeshellarg($lang)
            );
            $out = [];
            $rc  = 0;
            @exec($cmd, $out, $rc);
            $cost = round(microtime(true) - $t0, 1);
            if (is_file($srt)) {
                line('    完成，耗时 ' . $cost . 's');
            } else {
                line('    失败（rc=' . $rc . '，耗时 ' . $cost . 's）：' . trim(implode(' ', array_slice($out, -3))));
            }
        }
        line('');
        line('回收登记：');
        print_r(SubtitleService::harvestBitmap(0));
        break;

    default:
        line('未知命令：' . $cmd);
        line('可用：status | env | run | wait | render | oneshot');
        exit(1);
}
