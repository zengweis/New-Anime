#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 用 ffprobe 探测剧集片源的编码信息，写回 episode 表。
 *
 * 为什么需要：浏览器能不能播由「容器 / 视频编码 / 像素格式」三层决定，
 * 只看 .mkv 后缀只知道第一层。探测之后播放页才能给出准确结论，
 * 而不用先让用户撞一次「无法解码」。
 *
 *   php bin/probe_episodes.php              # 只探测还没探过的
 *   php bin/probe_episodes.php --all        # 全部重探
 *   php bin/probe_episodes.php --limit=100  # 限制数量
 *   php bin/probe_episodes.php --id=12      # 只探一集
 *   php bin/probe_episodes.php --quiet      # 只输出汇总
 */
if (PHP_SAPI !== 'cli') {
    exit("仅限命令行运行\n");
}

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Database;
use App\Service\MediaProbe;

$args   = array_slice($argv, 1);
$all    = in_array('--all', $args, true);
$quiet  = in_array('--quiet', $args, true);
$limit  = 0;
$onlyId = 0;

foreach ($args as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) {
        $limit = (int) $m[1];
    } elseif (preg_match('/^--id=(\d+)$/', $a, $m)) {
        $onlyId = (int) $m[1];
    }
}

// ---- 选中待探测的剧集 ----
$where  = [];
$bind   = [];

if ($onlyId > 0) {
    $where[] = '`id` = :id';
    $bind['id'] = $onlyId;
} elseif (!$all) {
    $where[] = '`probe_at` IS NULL';
}

$sql = 'SELECT `id`,`file_path` FROM `episode`';
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY `id` ASC';
if ($limit > 0) {
    $sql .= ' LIMIT ' . $limit;
}

$rows = Database::all($sql, $bind);

if ($rows === []) {
    echo "没有需要探测的剧集。\n";
    exit(0);
}

echo $all || $onlyId > 0 ? "== 全量重探 ==\n" : "== 探测未处理剧集 ==\n";
printf("待处理 %d 集\n\n", count($rows));

$ok = 0;
$bad = 0;
$fail = [];

foreach ($rows as $r) {
    $id = (int) $r['id'];
    $res = MediaProbe::probeEpisode($id, true);

    if (!empty($res['ok'])) {
        $ok++;
        if (!$quiet) {
            $ep = Database::one(
                'SELECT `vcodec`,`acodec`,`pix_fmt`,`sub_tracks` FROM `episode` WHERE `id` = :id',
                ['id' => $id]
            );
            printf(
                "  #%-6d %-8s %-8s %-16s 字幕 %d 轨\n",
                $id,
                (string) ($ep['vcodec'] ?? '-'),
                (string) ($ep['acodec'] ?? '-'),
                (string) ($ep['pix_fmt'] ?? '-'),
                (int) ($ep['sub_tracks'] ?? 0)
            );
        }
    } else {
        $bad++;
        $fail[] = sprintf('#%d %s', $id, (string) ($res['error'] ?? '未知错误'));
        if (!$quiet) {
            printf("  #%-6d 失败：%s\n", $id, (string) ($res['error'] ?? '未知错误'));
        }
    }
}

// ---- 编码分布小结 ----
$dist = Database::all(
    "SELECT COALESCE(`vcodec`,'(未探测)') AS v,
            SUM(CASE WHEN `pix_fmt` REGEXP '1[0246](le|be)?\$' THEN 1 ELSE 0 END) AS ten,
            COUNT(*) AS c
     FROM `episode` GROUP BY v ORDER BY c DESC"
);

echo "\n-- 编码分布 --\n";
foreach ($dist as $d) {
    printf("  %-12s %4d 集（其中 10bit %d 集）\n", (string) $d['v'], (int) $d['c'], (int) $d['ten']);
}

printf("\n成功 %d 集，失败 %d 集\n", $ok, $bad);
if ($fail !== []) {
    echo "失败明细：\n";
    foreach ($fail as $f) {
        echo '  - ' . $f . "\n";
    }
}
