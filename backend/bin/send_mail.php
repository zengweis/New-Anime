#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 消费邮件队列（SMTP 异步发送）。
 *   php bin/send_mail.php        # 最多发 30 封
 *   php bin/send_mail.php 100
 */
if (PHP_SAPI !== 'cli') {
    exit("仅限命令行运行\n");
}

require __DIR__ . '/../src/bootstrap.php';

use App\Service\FetchService;
use App\Service\MailService;

date_default_timezone_set((string) cfg('timezone', 'Asia/Shanghai'));

$limit = isset($argv[1]) ? max(1, (int) $argv[1]) : 30;
$t0    = microtime(true);

try {
    $r = MailService::processQueue($limit);
} catch (Throwable $e) {
    fwrite(STDERR, "[mail] 异常: {$e->getMessage()}\n");
    exit(1);
}

$cost = (int) ((microtime(true) - $t0) * 1000);

if ($r['sent'] > 0 || $r['failed'] > 0) {
    $msg = sprintf('已发 %d · 失败 %d · 剩余 %d', $r['sent'], $r['failed'], $r['remain']);
    FetchService::logJob('mail', $r['failed'] === 0, $r['sent'], $msg, $cost);
    printf("[%s] %s (%d ms)\n", date('Y-m-d H:i:s'), $msg, $cost);
}

exit(0);
