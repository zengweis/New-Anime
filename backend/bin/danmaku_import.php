#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 弹幕导入（命令行）。
 *
 *   php bin/danmaku_import.php <episodeId> <file.xml|json> [--user=<id>] [--dry]
 *   php bin/danmaku_import.php <episodeId> --stdin [--user=<id>]
 *   php bin/danmaku_import.php --stats [<episodeId>]
 *
 * 为什么要有 CLI 这一路：后台管理页能导入，但它走 JSON body，而 nginx 的
 * client_max_body_size 是默认的 1MB —— 单集弹幕 XML 一般在这个量级以内，
 * 更大的（多集合并导出、带进度的全量备份）就得走这里，没有体积上限。
 *
 * --user 默认取第一个管理员：弹幕的 user_id 是必填的（业务要求能追到人），
 * 导入的内容算在执行导入的人名下，source='import' 与站内发送区分开。
 */

if (PHP_SAPI !== 'cli') {
    exit("仅限命令行运行\n");
}

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Database;
use App\Service\DanmakuService;

$argvList = $argv;
array_shift($argvList);

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
    $u = ['B', 'KB', 'MB'];
    $i = 0;
    $v = (float) $b;
    while ($v >= 1024 && $i < count($u) - 1) {
        $v /= 1024;
        $i++;
    }
    return ($v >= 100 || $i === 0 ? number_format($v, 0) : number_format($v, 1)) . $u[$i];
}

/** 归属用户：--user 指定，否则取第一个管理员 */
function resolveUser(array $flags): array
{
    if (isset($flags['user'])) {
        $id = (int) $flags['user'];
        $u  = Database::one('SELECT `id`,`username`,`role` FROM `user` WHERE `id` = :i', ['i' => $id]);
        if ($u === null) {
            line("[FAIL] 用户 #{$id} 不存在");
            exit(1);
        }
        return $u;
    }
    $u = Database::one("SELECT `id`,`username`,`role` FROM `user` WHERE `role` = 'admin' ORDER BY `id` LIMIT 1");
    if ($u === null) {
        line('[FAIL] 库里没有管理员，请用 --user=<id> 指定归属用户');
        exit(1);
    }
    return $u;
}

// ------------------------------------------------------------------ 统计
if (isset($flags['stats'])) {
    $epId = (int) ($args[0] ?? 0);
    $where = $epId > 0 ? 'WHERE `episode_id` = ' . $epId : '';
    line(sprintf('%-6s %-9s %-9s %-9s', '集', '可见', '已删除', '合计'));
    $rows = Database::all(
        "SELECT `episode_id` AS ep,
                SUM(CASE WHEN `status` = 0 THEN 1 ELSE 0 END) AS alive,
                SUM(CASE WHEN `status` = 9 THEN 1 ELSE 0 END) AS dead,
                COUNT(*) AS total
         FROM `danmaku` {$where} GROUP BY `episode_id` ORDER BY `episode_id` DESC LIMIT 100"
    );
    foreach ($rows as $r) {
        line(sprintf('%-6d %-9d %-9d %-9d', $r['ep'], $r['alive'], $r['dead'], $r['total']));
    }
    if (!$rows) {
        line('（还没有任何弹幕）');
    }
    $all = Database::one('SELECT COUNT(*) AS c, ROUND(SUM(LENGTH(`text`))/1024) AS kb FROM `danmaku`');
    line();
    line(sprintf('全库 %d 条，文本合计 %s', $all['c'], human((int) $all['kb'] * 1024)));
    exit(0);
}

// ------------------------------------------------------------------ 导入
$epId = (int) ($args[0] ?? 0);
if ($epId <= 0) {
    line('用法：php bin/danmaku_import.php <episodeId> <file.xml|json> [--user=<id>] [--dry]');
    line('      php bin/danmaku_import.php --stats [<episodeId>]');
    exit(1);
}

$ep = DanmakuService::episode($epId);
if ($ep === null) {
    line("[FAIL] 剧集 #{$epId} 不存在");
    exit(1);
}

if (isset($flags['stdin'])) {
    $raw = (string) stream_get_contents(STDIN);
    $from = 'stdin';
} else {
    $file = (string) ($args[1] ?? '');
    if ($file === '' || !is_file($file)) {
        line('[FAIL] 请给出存在的弹幕文件（.xml 或 .json）');
        exit(1);
    }
    $raw  = (string) file_get_contents($file);
    $from = $file;
}

$u = resolveUser($flags);

line(sprintf('剧集   : #%d（时长 %s）', $epId, (int) ($ep['duration'] ?? 0) > 0 ? (int) $ep['duration'] . 's' : '未知'));
line(sprintf('归属   : #%d %s', (int) $u['id'], $u['username']));
line(sprintf('来源   : %s（%s）', $from, human(strlen($raw))));
line();

$parsed = DanmakuService::parse($raw);
line(sprintf('解析   : %d 条', count($parsed)));

if ($parsed === []) {
    line('[FAIL] 没解析出任何弹幕，请确认是 B 站 XML（<d p="…">文本</d>）或弹幕 JSON');
    exit(1);
}

if (isset($flags['dry'])) {
    line('（--dry：只解析不写入）前 5 条：');
    foreach (array_slice($parsed, 0, 5) as $p) {
        line(sprintf('  %8.2fs  mode=%d size=%d #%06X  %s', $p['t'], $p['mode'], $p['size'], $p['color'], $p['text']));
    }
    exit(0);
}

$t0 = microtime(true);
$r  = DanmakuService::importRaw($epId, (int) $u['id'], $raw);
$cost = round(microtime(true) - $t0, 2);

line(sprintf('写入   : %d 条，跳过 %d 条，耗时 %ss', $r['inserted'], $r['skipped'], $cost));
foreach ($r['errors'] as $e) {
    line('  ! ' . $e);
}
line();
line(sprintf('本集现在可见 %d 条', (int) Database::val(
    'SELECT COUNT(*) FROM `danmaku` WHERE `episode_id` = :e AND `status` = 0',
    ['e' => $epId]
)));
