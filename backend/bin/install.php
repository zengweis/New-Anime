#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 初始化数据库：执行 migrations/schema.sql
 *   php bin/install.php
 */
if (PHP_SAPI !== 'cli') {
    exit("仅限命令行运行\n");
}

require __DIR__ . '/../src/bootstrap.php';

$c   = cfg('db');
$sql = file_get_contents(APP_ROOT . '/migrations/schema.sql');
if ($sql === false || trim($sql) === '') {
    exit("读取 schema.sql 失败\n");
}

$dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $c['host'], $c['port'], $c['charset']);

try {
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS   => true,
        PDO::ATTR_EMULATE_PREPARES         => false,
    ]);
} catch (PDOException $e) {
    exit('连接失败：' . $e->getMessage() . "\n");
}

// 建库（若不存在）
$pdo->exec(sprintf(
    'CREATE DATABASE IF NOT EXISTS `%s` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    $c['name']
));
$pdo->exec('USE `' . $c['name'] . '`');

echo "正在导入 schema ...\n";

try {
    $pdo->exec($sql);
} catch (PDOException $e) {
    // 多语句执行时用 nextRowset 继续，但 exec 只报第一个错误
    exit('导入失败：' . $e->getMessage() . "\n");
}

// 逐表确认
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo "已创建 " . count($tables) . " 张表：\n";
foreach ($tables as $t) {
    $n = (int) $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
    printf("  %-16s %d 行\n", $t, $n);
}

echo "\n完成。接下来执行： php bin/init_admin.php <用户名> <邮箱> <密码>\n";
