#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 扫描 qBittorrent 已完成任务，把视频文件登记进 episode 表。
 * 幂等，可重复执行。
 *
 *   php bin/sync_downloads.php
 */
if (PHP_SAPI !== 'cli') {
    exit("仅限命令行运行\n");
}

require __DIR__ . '/../src/bootstrap.php';

use App\Service\FetchService;
use App\Service\SyncService;

date_default_timezone_set((string) cfg('timezone', 'Asia/Shanghai'));

$t0 = microtime(true);

try {
    $r = (new SyncService())->run();
} catch (Throwable $e) {
    FetchService::logJob('sync', false, 0, $e->getMessage(), (int) ((microtime(true) - $t0) * 1000));
    fwrite(STDERR, "[sync] 异常: {$e->getMessage()}\n");
    exit(1);
}

$cost = (int) ((microtime(true) - $t0) * 1000);

$msg = sprintf(
    '任务 %d 个 · 已完成 %d · 新导入种子 %d 个 · 新增集数 %d · 跳过 %d',
    $r['torrents'],
    $r['finished'],
    $r['imported'],
    $r['episodes'],
    $r['skipped']
);

FetchService::logJob(
    'sync',
    empty($r['errors']),
    (int) $r['episodes'],
    $msg . (!empty($r['errors']) ? ' | ' . implode(' ; ', $r['errors']) : ''),
    $cost
);

printf("[%s] %s (%d ms)\n", date('Y-m-d H:i:s'), $msg, $cost);

foreach ($r['errors'] as $e) {
    fwrite(STDERR, "  ! {$e}\n");
}

exit(0);
