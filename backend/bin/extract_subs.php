#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 字幕抽取 / 回填。
 *
 *   php bin/extract_subs.php                    # 抽所有待处理的（默认上限 50 集）
 *   php bin/extract_subs.php --episode=25       # 只抽某一集
 *   php bin/extract_subs.php --all              # 全库重抽（含已抽过的）
 *   php bin/extract_subs.php --limit=10         # 限制本次处理集数
 *   php bin/extract_subs.php --force            # 忽略缓存强制重抽
 *   php bin/extract_subs.php --list             # 只列出状态，不动手
 *
 * 单集开销实测约 0.5 秒（纯解封装，不解视频码），所以整库回填也是秒级的事。
 */
if (PHP_SAPI !== 'cli') {
    exit("仅限命令行运行\n");
}

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Database;
use App\Service\SubtitleService;

date_default_timezone_set((string) cfg('timezone', 'Asia/Shanghai'));

$opt    = getopt('', ['episode::', 'limit::', 'all', 'force', 'list']);
$limit  = max(1, (int) ($opt['limit'] ?? 50));
$force  = array_key_exists('force', $opt);
$all    = array_key_exists('all', $opt);
$list   = array_key_exists('list', $opt);
$oneEp  = isset($opt['episode']) ? (int) $opt['episode'] : 0;

if ($oneEp > 0) {
    $ids = [$oneEp];
} elseif ($all) {
    $ids = array_column(Database::all(
        'SELECT `id` FROM `episode` WHERE `file_path` <> \'\' ORDER BY `id` LIMIT ' . $limit
    ), 'id');
} else {
    $ids = array_column(SubtitleService::pending($limit), 'id');
}

if (!$ids) {
    exit("没有待抽取字幕的剧集\n");
}

echo '字幕缓存目录：' . SubtitleService::root() . "\n";
echo 'ffmpeg：' . SubtitleService::ffmpegBin() . "\n";
echo '本次处理 ' . count($ids) . " 集\n\n";

if ($list) {
    foreach ($ids as $id) {
        $t = SubtitleService::tracks((int) $id);
        printf("  #%-5d 已有 %d 轨\n", (int) $id, count($t));
    }
    exit(0);
}

$t0 = microtime(true);
$stat = ['extracted' => 0, 'skipped' => 0, 'failed' => 0, 'tracks' => 0];

foreach ($ids as $id) {
    $id = (int) $id;
    $r  = SubtitleService::extract($id, $force);

    if ($r['busy']) {
        echo "  #{$id} 另一路抽取正在进行，跳过\n";
        $stat['skipped']++;
        continue;
    }

    if ($r['reason'] === 'extracted') {
        $n = count($r['tracks']);
        $stat['extracted']++;
        $stat['tracks'] += $n;
        $names = implode('、', array_map(
            static fn(array $t): string => $t['label'] . '(' . $t['cues'] . '条)',
            $r['tracks']
        ));
        echo "  #{$id} 抽出 {$n} 轨：{$names}\n";
    } elseif ($r['reason'] === 'no_subtitle_stream') {
        echo "  #{$id} 无字幕轨（不处理）\n";
        $stat['skipped']++;
    } elseif ($r['skipped']) {
        $stat['skipped']++;
    } else {
        $stat['failed']++;
        echo "  #{$id} 失败：{$r['error']}（{$r['reason']}）\n";
    }
}

$cost = sprintf('%.2f', microtime(true) - $t0);
echo "\n完成：抽出 {$stat['extracted']} 集 / {$stat['tracks']} 轨，"
    . "跳过 {$stat['skipped']} 集，失败 {$stat['failed']} 集，耗时 {$cost} 秒\n";

exit($stat['failed'] > 0 ? 1 : 0);
