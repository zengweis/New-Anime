#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 增量迁移：按文件名顺序执行 migrations/*.sql，已执行过的跳过。
 *   php bin/migrate.php                     # 应用全部未执行迁移
 *   php bin/migrate.php --list              # 只看状态
 *   php bin/migrate.php --baseline=<前缀>   # 把指定前缀的迁移标记为已执行（不执行）
 *
 * 与 bin/install.php 的分工：
 *   install.php  → 全新建库，跑 schema.sql（幂等，CREATE TABLE IF NOT EXISTS）
 *   migrate.php  → 已有库升级，跑 migrations/<版本>.sql，每个文件只跑一次
 *
 * 什么时候需要 --baseline：
 *   用 schema.sql 全新建的库里，历史迁移的改动**已经包含在 schema.sql 里**，
 *   但 schema_migration 表是空的。此时直接跑 migrate.php 会从第一个迁移开始重放，
 *   报 "Duplicate column name" 然后中止。先在全新库上打一次基线即可：
 *     php bin/migrate.php --baseline=2026-09-12
 *   它会把这些文件记为已执行（只登记、不执行），之后的增量才是真正要跑的。
 */
if (PHP_SAPI !== 'cli') {
    exit("仅限命令行运行\n");
}

require __DIR__ . '/../src/bootstrap.php';

$argvList  = array_slice($argv, 1);
$listOnly  = in_array('--list', $argvList, true);
$baseline  = null;
foreach ($argvList as $a) {
    if (str_starts_with($a, '--baseline=')) {
        $baseline = substr($a, strlen('--baseline='));
    } elseif ($a === '--baseline') {
        exit("--baseline 需要带前缀，例如 --baseline=2026-09-12\n");
    }
}

$c   = cfg('db');
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['name'], $c['charset']);

try {
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    exit('连接失败：' . $e->getMessage() . "\n");
}

// 迁移记录表：本身用 CREATE TABLE IF NOT EXISTS，故不占迁移号
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS `schema_migration` (
        `file`       VARCHAR(191) NOT NULL COMMENT "migrations/ 下的文件名",
        `batch`      INT NOT NULL DEFAULT 1,
        `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`file`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT="迁移执行记录"'
);

$done = $pdo->query('SELECT `file` FROM `schema_migration`')->fetchAll(PDO::FETCH_COLUMN);
$done = array_flip($done);

$files = glob(APP_ROOT . '/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

// schema.sql 属于「全量建库」，不参与增量迁移
$files = array_values(array_filter($files, static fn(string $f): bool => basename($f) !== 'schema.sql'));

if (!$files) {
    exit("migrations/ 下没有增量迁移文件\n");
}

$pending = array_values(array_filter($files, static fn(string $f): bool => !isset($done[basename($f)])));

// ---------------------------------------------------------------- 基线
if ($baseline !== null) {
    $hit = array_values(array_filter(
        $pending,
        static fn(string $f): bool => str_starts_with(basename($f), $baseline)
    ));
    if (!$hit) {
        exit("没有以 {$baseline} 开头且未执行的迁移文件\n");
    }

    echo "以下迁移将被标记为「已执行」（只登记、不运行）：\n";
    foreach ($hit as $f) {
        echo '  ' . basename($f) . "\n";
    }
    echo "\n注意：这一步不做任何校验，请确认这些改动确实已经在库里（例如由 schema.sql 建库带来）。\n";

    $b = 1 + (int) $pdo->query('SELECT COALESCE(MAX(`batch`), 0) FROM `schema_migration`')->fetchColumn();
    $st = $pdo->prepare('INSERT IGNORE INTO `schema_migration` (`file`, `batch`) VALUES (:f, :b)');
    foreach ($hit as $f) {
        $st->execute(['f' => basename($f), 'b' => $b]);
    }
    printf("\n已登记 %d 个迁移为基线（batch %d），未执行任何 SQL\n", count($hit), $b);
    exit(0);
}

if ($listOnly) {
    foreach ($files as $f) {
        $name = basename($f);
        printf("  [%s] %s\n", isset($done[$name]) ? '已执行' : '  待执行', $name);
    }
    printf("\n共 %d 个迁移，%d 个待执行\n", count($files), count($pending));
    exit(0);
}

if (!$pending) {
    echo "数据库已是最新，无需迁移\n";
    exit(0);
}

$batch = 1 + (int) $pdo->query('SELECT COALESCE(MAX(`batch`), 0) FROM `schema_migration`')->fetchColumn();

foreach ($pending as $f) {
    $name = basename($f);
    $sql  = (string) file_get_contents($f);
    if (trim($sql) === '') {
        echo "  跳过空文件 {$name}\n";
        continue;
    }

    echo "==> {$name}\n";
    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        // 迁移是「要么整份成功、要么停下让人看」——不做半途容错，避免留下半升级状态
        exit("  失败：{$e->getMessage()}\n  已中止，后续迁移未执行。\n");
    }

    $st = $pdo->prepare('INSERT INTO `schema_migration` (`file`, `batch`) VALUES (:f, :b)');
    $st->execute(['f' => $name, 'b' => $batch]);
    echo "  完成\n";
}

printf("\n已应用 %d 个迁移（batch %d）\n", count($pending), $batch);
