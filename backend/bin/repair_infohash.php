#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 一次性数据修复：把历史遗留的 Base32 infohash 归一化成 40 位十六进制。
 *
 * 背景：dmhy 的磁力链接用 Base32 编码 infohash（32 字符），而 qBittorrent 一律以
 * 40 位十六进制回传。修复前写库的是 Base32，导致 SyncService 按 hash 匹配不上 qB 的
 * 任务，下载完成后剧集永远同步不进库。
 *
 *   php bin/repair_infohash.php          # 干跑，只报告
 *   php bin/repair_infohash.php --apply  # 实际写库
 */
if (PHP_SAPI !== 'cli') {
    exit("仅限命令行运行\n");
}

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Database;
use App\Service\TitleParser;

$apply = in_array('--apply', array_slice($argv, 1), true);

// 表 => 需要归一化的列
$targets = [
    'rss_item' => ['infohash', 'torrent_hash'],
    'episode'  => ['infohash'],
];

$scanned = 0;
$changed = 0;
$skipped = 0;

echo $apply ? "== 实际写库 ==\n" : "== 干跑（加 --apply 才写库）==\n";

foreach ($targets as $table => $cols) {
    foreach ($cols as $col) {
        $rows = Database::all(
            "SELECT `id`, `{$col}` AS `h` FROM `{$table}`
             WHERE `{$col}` IS NOT NULL AND CHAR_LENGTH(`{$col}`) = 32"
        );
        foreach ($rows as $r) {
            $scanned++;
            $old = (string) $r['h'];
            $new = TitleParser::base32ToHex($old);
            if ($new === null || $new === $old) {
                $skipped++;
                continue;
            }
            $changed++;
            if ($apply) {
                Database::update($table, [$col => $new], '`id` = :id', ['id' => (int) $r['id']]);
            }
        }
        printf("  %-10s %-14s 待修复 %d 条\n", $table, $col, count($rows));
    }
}

// SyncService 从 qB 补录的条目用 `torrent:<hash>` 当 guid，老数据里可能也是 Base32
$guids = Database::all(
    'SELECT `id`, `guid` FROM `rss_item` WHERE `guid` LIKE "torrent:%" AND CHAR_LENGTH(`guid`) = 40'
);
foreach ($guids as $g) {
    $old = substr((string) $g['guid'], 8);
    $new = TitleParser::base32ToHex($old);
    if ($new === null) {
        continue;
    }
    $changed++;
    if ($apply) {
        Database::update('rss_item', ['guid' => 'torrent:' . $new], '`id` = :id', ['id' => (int) $g['id']]);
    }
}
printf("  %-10s %-14s 待修复 %d 条\n", 'rss_item', 'guid', count($guids));

printf("\n扫描 %d 条，需修复 %d 条，跳过 %d 条\n", $scanned + count($guids), $changed, $skipped);
echo $apply ? "修复完成。\n" : "干跑结束，未写库。\n";
