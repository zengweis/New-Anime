#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 初始化默认订阅源（幂等）。
 *   php bin/seed.php
 * 默认 auto_down=0 —— 先只入库，规则配好后再开自动下载。
 */
if (PHP_SAPI !== 'cli') {
    exit("仅限命令行运行\n");
}

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Database;
use App\Service\DmhyRss;

$feeds = [
    ['name' => 'dmhy 動畫', 'url' => DmhyRss::url('', DmhyRss::SORT_ANIME), 'min_res' => '1080'],
    ['name' => 'dmhy 全部', 'url' => DmhyRss::url('', DmhyRss::SORT_ALL),   'min_res' => '1080'],
];

foreach ($feeds as $f) {
    $exist = Database::one('SELECT `id` FROM `rss_feed` WHERE `url` = :u LIMIT 1', ['u' => $f['url']]);
    if ($exist !== null) {
        printf("已存在 #%d  %s\n", (int) $exist['id'], $f['name']);
        continue;
    }
    $id = Database::insert('rss_feed', [
        'name'      => $f['name'],
        'url'       => $f['url'],
        'enabled'   => 1,
        'auto_down' => 0,
        'min_res'   => $f['min_res'],
    ]);
    printf("已添加 #%d  %s  %s\n", $id, $f['name'], $f['url']);
}

echo "\n当前订阅源：\n";
foreach (Database::all('SELECT `id`,`name`,`url`,`enabled`,`auto_down` FROM `rss_feed`') as $r) {
    printf("  #%d %-12s enabled=%d auto_down=%d  %s\n",
        (int) $r['id'], $r['name'], (int) $r['enabled'], (int) $r['auto_down'], $r['url']);
}
