#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 抓取 RSS 并自动加种。
 *   php bin/fetch_rss.php            # 全部启用的源
 *   php bin/fetch_rss.php 3          # 只抓 id=3 的源
 *   php bin/fetch_rss.php 3 50       # 限制本轮最多加种 50 个
 */
if (PHP_SAPI !== 'cli') {
    exit("仅限命令行运行\n");
}

require __DIR__ . '/../src/bootstrap.php';

use App\Service\FetchService;

date_default_timezone_set((string) cfg('timezone', 'Asia/Shanghai'));

$feedId = isset($argv[1]) && $argv[1] !== '' ? (int) $argv[1] : null;
$limit  = isset($argv[2]) ? max(1, (int) $argv[2]) : 300;
$t0     = microtime(true);

try {
    $r = (new FetchService())->fetchAndDownload($feedId, $limit);
} catch (Throwable $e) {
    FetchService::logJob('fetch', false, 0, $e->getMessage(), (int) ((microtime(true) - $t0) * 1000));
    fwrite(STDERR, "[fetch] 异常: {$e->getMessage()}\n");
    exit(1);
}

$cost = (int) ((microtime(true) - $t0) * 1000);
$f    = $r['fetch'];
$a    = $r['auto'];

$msg = sprintf(
    '源 %d 个 · 抓取 %d 条 · 新增 %d · 源级过滤 %d · 加种 %d · 跳过 %d',
    $f['feeds'],
    $f['fetched'],
    $f['new'],
    $f['filtered'],
    $a['added'] ?? 0,
    $a['blocked'] ?? 0
);

FetchService::logJob(
    'fetch',
    empty($f['errors']),
    (int) $f['new'],
    $msg . (!empty($f['errors']) ? ' | ERR: ' . implode(' ; ', $f['errors']) : ''),
    $cost
);

printf("[%s] %s (%d ms)\n", date('Y-m-d H:i:s'), $msg, $cost);

foreach ($a['notes'] ?? [] as $n) {
    echo "  · {$n}\n";
}
foreach ($f['errors'] as $e) {
    fwrite(STDERR, "  ! {$e}\n");
}

exit(empty($f['errors']) ? 0 : 2);
