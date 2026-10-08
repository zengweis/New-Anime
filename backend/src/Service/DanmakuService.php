<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Database;
use App\Core\Log;

/**
 * 弹幕。
 *
 * 定位：与视频字节流完全无关的旁路数据 —— 不参与 remux / 上传 / 解码链路，
 * 纯 HTTP JSON + 前端 canvas 叠加。它不会给这台 2 核机器增加任何处理负担，
 * 唯一的真实成本在客户端的渲染。
 *
 * 规则（2026-09-24 定）：
 *   · 只有登录用户能发，user_id 必填（要能追到是哪个人发的哪条）
 *   · 不做审核，写入即可见；后台可批量删除（软删 status=9，误删可救）
 *   · 后台可导入，导入的弹幕挂在执行导入的管理员名下，source='import'
 *   · 不做时间轴偏移/修正
 */
final class DanmakuService
{
    /** 单条文本长度上限（按字符数，不是字节） */
    public const MAX_TEXT = 120;

    /** 允许的弹幕模式：1 滚动 · 4 底部固定 · 5 顶部固定 */
    public const MODES = [1, 4, 5];

    /** 同一用户在同一集的弹幕总量上限（含已删除，避免「删了再刷」） */
    public const MAX_PER_EPISODE = 200;

    /** 滑动窗口限速：窗口秒数内最多允许的条数 */
    public const BURST_WINDOW = 10;
    public const BURST_MAX = 5;

    /** 相同文本在此窗口内不允许重复（秒） */
    public const DUP_WINDOW = 30;

    /** 时长未知时的兜底上界：6 小时 */
    private const FALLBACK_MAX_MS = 6 * 3600 * 1000;

    /** 单次读取条数上限。真到十万条量级再改成分段拉取（现在不做） */
    private const READ_LIMIT = 20000;

    /** 批量导入时每条 INSERT 语句最多带多少行 */
    private const IMPORT_CHUNK = 500;

    // ==================================================================
    //  读
    // ==================================================================

    public static function episode(int $episodeId): ?array
    {
        return Database::one(
            'SELECT `id`,`duration` FROM `episode` WHERE `id` = :e LIMIT 1',
            ['e' => $episodeId]
        );
    }

    /**
     * 一集的可见弹幕，按时间升序。
     *
     * 负载用**数组**而不是对象：每条 5 个字段，用键名的话键名占的字节比数据还多，
     * 3000 条能差出三成体积。字段顺序固定 [t_ms, mode, color, size, text]，前端按下标读。
     *
     * @return array{items:array<int,array<int,mixed>>,total:int,sig:string}
     */
    public static function payload(int $episodeId): array
    {
        $rows = Database::all(
            'SELECT `t_ms`,`mode`,`color`,`size`,`text` FROM `danmaku`
             WHERE `episode_id` = :e AND `status` = 0
             ORDER BY `t_ms` ASC, `id` ASC
             LIMIT ' . self::READ_LIMIT,
            ['e' => $episodeId]
        );

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                (int) $r['t_ms'],
                (int) $r['mode'],
                (int) $r['color'],
                (int) $r['size'],
                (string) $r['text'],
            ];
        }

        // 版本指纹：可见条数 + 最大 id。用它做 ETag，重复观看直接 304。
        //
        // 必须**只统计可见行**（status = 0）：软删只是翻 status，条数和 max(id) 都不变，
        // 用全表统计的话删完 ETag 还是老值，客户端会一直吃缓存、被删的弹幕照样显示。
        $sig = Database::one(
            'SELECT COUNT(*) AS `c`, COALESCE(MAX(`id`), 0) AS `m`
             FROM `danmaku` WHERE `episode_id` = :e AND `status` = 0',
            ['e' => $episodeId]
        );

        return [
            'items' => $items,
            'total' => count($items),
            'sig'   => (int) ($sig['c'] ?? 0) . '-' . (int) ($sig['m'] ?? 0),
        ];
    }

    // ==================================================================
    //  写
    // ==================================================================

    /**
     * 发送一条弹幕。
     *
     * @return array{id:int,t_ms:int,mode:int,color:int,size:int,text:string}
     * @throws \InvalidArgumentException 校验或限速不通过（消息可直接展示给用户）
     */
    public static function create(
        int $episodeId,
        int $userId,
        int $tMs,
        string $rawText,
        int $mode = 1,
        int $color = 0xFFFFFF,
        int $size = 1,
        ?string $ip = null
    ): array {
        $ep = self::episode($episodeId);
        if ($ep === null) {
            throw new \InvalidArgumentException('剧集不存在');
        }
        if ($userId <= 0) {
            throw new \InvalidArgumentException('需要登录才能发弹幕');
        }

        $text = self::cleanText($rawText);
        if ($text === '') {
            throw new \InvalidArgumentException('弹幕内容不能为空');
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            throw new \InvalidArgumentException('弹幕最长 ' . self::MAX_TEXT . ' 个字');
        }

        $mode  = in_array($mode, self::MODES, true) ? $mode : 1;
        $size  = in_array($size, [0, 1, 2], true) ? $size : 1;
        $color = self::normalizeColor($color);

        $tMs = max(0, $tMs);
        $max = self::maxMs($ep);
        if ($tMs > $max) {
            throw new \InvalidArgumentException('弹幕时间超出了视频长度');
        }

        self::assertRate($episodeId, $userId, $text);

        $id = (int) Database::insert('danmaku', [
            'episode_id' => $episodeId,
            'user_id'    => $userId,
            't_ms'       => $tMs,
            'mode'       => $mode,
            'color'      => $color,
            'size'       => $size,
            'text'       => $text,
            'source'     => 'web',
            'ip'         => $ip,
        ]);

        return [
            'id'    => $id,
            't_ms'  => $tMs,
            'mode'  => $mode,
            'color' => $color,
            'size'  => $size,
            'text'  => $text,
        ];
    }

    /**
     * 刷屏护栏。
     *
     * 这里全用 danmaku 表自身做窗口查询，不引 Redis —— 2 核机器上加一个常驻服务
     * 只为了存几个计数器不划算，而这个量级下索引扫描的成本可以忽略。
     *
     * 注：`created_at` 是 DATETIME（秒精度），所以做不出「最小间隔 800ms」这类判定，
     * 用「10 秒 ≤ 5 条」代替，效果等价且可测。
     */
    private static function assertRate(int $episodeId, int $userId, string $text): void
    {
        $burst = (int) Database::val(
            'SELECT COUNT(*) FROM `danmaku`
             WHERE `user_id` = :u AND `episode_id` = :e
               AND `created_at` > NOW() - INTERVAL ' . self::BURST_WINDOW . ' SECOND',
            ['u' => $userId, 'e' => $episodeId]
        );
        if ($burst >= self::BURST_MAX) {
            throw new \InvalidArgumentException('发得太快了，缓一下再发');
        }

        $total = (int) Database::val(
            'SELECT COUNT(*) FROM `danmaku` WHERE `user_id` = :u AND `episode_id` = :e',
            ['u' => $userId, 'e' => $episodeId]
        );
        if ($total >= self::MAX_PER_EPISODE) {
            throw new \InvalidArgumentException('这一集的弹幕数量已达上限（' . self::MAX_PER_EPISODE . ' 条）');
        }

        $dup = Database::val(
            'SELECT 1 FROM `danmaku`
             WHERE `user_id` = :u AND `episode_id` = :e AND `text` = :t
               AND `created_at` > NOW() - INTERVAL ' . self::DUP_WINDOW . ' SECOND
             LIMIT 1',
            ['u' => $userId, 'e' => $episodeId, 't' => $text]
        );
        if ($dup !== null) {
            throw new \InvalidArgumentException('同样的弹幕刚刚发过了');
        }
    }

    /** 文本清洗：去控制字符、换行折叠成空格、去首尾空白 */
    public static function cleanText(string $raw): string
    {
        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $raw) ?? $raw;
        $t = str_replace(["\r", "\n", "\t"], ' ', $t);
        $t = preg_replace('/ {2,}/u', ' ', $t) ?? $t;
        return trim($t);
    }

    /**
     * 颜色归一化。接受 0xRRGGBB 整数或 "#RRGGBB" 字符串。
     * 纯黑在深色画面上等于看不见，统一挡成白色。
     */
    public static function normalizeColor(mixed $v): int
    {
        if (is_string($v)) {
            $v = trim($v);
            if (str_starts_with($v, '#')) {
                $v = substr($v, 1);
            }
            if ($v !== '' && ctype_xdigit($v)) {
                $v = hexdec($v);
            }
        }
        $c = (int) $v;
        if ($c < 0) {
            $c = 0;
        }
        if ($c > 0xFFFFFF) {
            $c &= 0xFFFFFF;
        }
        return $c === 0 ? 0xFFFFFF : $c;
    }

    private static function maxMs(array $ep): int
    {
        $dur = (int) ($ep['duration'] ?? 0);
        return $dur > 0 ? $dur * 1000 + 5000 : self::FALLBACK_MAX_MS;
    }

    // ==================================================================
    //  后台
    // ==================================================================

    /**
     * 弹幕列表（管理端）。$f: episode_id / status(0|9|all) / kw / user_id / page / per
     *
     * @return array{list:array<int,array<string,mixed>>,total:int,page:int,per:int}
     */
    public static function adminList(array $f): array
    {
        [$where, $bind] = self::adminWhere($f);

        $per  = max(10, min(200, (int) ($f['per'] ?? 50)));
        $page = max(1, (int) ($f['page'] ?? 1));
        $off  = ($page - 1) * $per;

        $list = Database::all(
            'SELECT d.`id`, d.`episode_id`, d.`user_id`, d.`t_ms`, d.`mode`, d.`color`, d.`size`,
                    d.`text`, d.`status`, d.`source`, d.`ip`, d.`created_at`,
                    u.`username`, u.`nickname`,
                    e.`ep_no`, e.`anime_id`,
                    a.`title_cn`, a.`title` AS `anime_title`
             FROM `danmaku` d
             LEFT JOIN `user` u    ON u.`id` = d.`user_id`
             LEFT JOIN `episode` e ON e.`id` = d.`episode_id`
             LEFT JOIN `anime` a   ON a.`id` = e.`anime_id`
             WHERE ' . $where . '
             ORDER BY d.`id` DESC
             LIMIT ' . $per . ' OFFSET ' . $off,
            $bind
        );

        $total = (int) Database::val(
            'SELECT COUNT(*) FROM `danmaku` d WHERE ' . $where,
            $bind
        );

        return ['list' => $list, 'total' => $total, 'page' => $page, 'per' => $per];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function adminWhere(array $f): array
    {
        $where = ['1 = 1'];
        $bind  = [];

        $epId = (int) ($f['episode_id'] ?? 0);
        if ($epId > 0) {
            $where[]     = 'd.`episode_id` = :e';
            $bind['e']   = $epId;
        }

        $uid = (int) ($f['user_id'] ?? 0);
        if ($uid > 0) {
            $where[]     = 'd.`user_id` = :u';
            $bind['u']   = $uid;
        }

        $st = (string) ($f['status'] ?? '0');
        if ($st === '9') {
            $where[] = 'd.`status` = 9';
        } elseif ($st !== 'all') {
            $where[] = 'd.`status` = 0';
        }

        $kw = trim((string) ($f['kw'] ?? ''));
        if ($kw !== '') {
            $where[]      = 'd.`text` LIKE :kw';
            $bind['kw']   = '%' . $kw . '%';
        }

        return [implode(' AND ', $where), $bind];
    }

    /** 剧集清单（含弹幕计数），给后台的下拉框用 */
    public static function adminEpisodes(int $limit = 500): array
    {
        return Database::all(
            'SELECT e.`id`, e.`ep_no`, e.`title`, e.`duration`,
                    a.`title_cn`, a.`title` AS `anime_title`,
                    SUM(CASE WHEN d.`status` = 0 THEN 1 ELSE 0 END) AS `alive`,
                    SUM(CASE WHEN d.`status` = 9 THEN 1 ELSE 0 END) AS `deleted`
             FROM `episode` e
             LEFT JOIN `anime` a   ON a.`id` = e.`anime_id`
             LEFT JOIN `danmaku` d ON d.`episode_id` = e.`id`
             GROUP BY e.`id`
             ORDER BY e.`id` DESC
             LIMIT ' . max(1, min(2000, $limit))
        );
    }

    /**
     * 批量删除（软删）。返回实际影响的条数。
     *
     * @param array<int,mixed> $ids
     */
    public static function adminDelete(array $ids, int $adminId): int
    {
        $clean = [];
        foreach ($ids as $i) {
            $n = (int) $i;
            if ($n > 0) {
                $clean[$n] = true;
            }
        }
        $clean = array_slice(array_keys($clean), 0, 1000);
        if ($clean === []) {
            return 0;
        }

        // id 已全部强制成整数，直接内联（没有注入面，也避免几百个占位符）
        $in = implode(',', $clean);
        $st = Database::run(
            'UPDATE `danmaku` SET `status` = 9 WHERE `id` IN (' . $in . ') AND `status` = 0'
        );
        $n = $st->rowCount();

        Log::write('danmaku', '批量删除弹幕', [
            'admin' => $adminId, 'count' => $n, 'ids' => $clean,
        ]);
        return $n;
    }

    /** 恢复（撤销软删），批量删除点错了能救回来 */
    public static function adminRestore(array $ids, int $adminId): int
    {
        $clean = [];
        foreach ($ids as $i) {
            $n = (int) $i;
            if ($n > 0) {
                $clean[$n] = true;
            }
        }
        $clean = array_slice(array_keys($clean), 0, 1000);
        if ($clean === []) {
            return 0;
        }
        $st = Database::run(
            'UPDATE `danmaku` SET `status` = 0 WHERE `id` IN (' . implode(',', $clean) . ') AND `status` = 9'
        );
        $n = $st->rowCount();
        Log::write('danmaku', '批量恢复弹幕', ['admin' => $adminId, 'count' => $n]);
        return $n;
    }

    // ==================================================================
    //  导入
    // ==================================================================

    /**
     * 导入弹幕文本。支持两种格式：
     *
     *   B 站 XML   <d p="秒,模式,字号,颜色,时间戳,池,uid,行号">文本</d>
     *   JSON       [[秒, 模式, 颜色, 文本, 字号?], ...]
     *              或 [{"t":秒,"text":"…","mode":1,"color":16777215,"size":1}, ...]
     *              或 {"items":[ ... ]}
     *
     * 导入的弹幕归属于执行导入的管理员（user_id 必填是业务要求），
     * 用 source='import' 与站内发送区分开，后台可以按来源筛。
     *
     * @return array{parsed:int,inserted:int,skipped:int,errors:array<int,string>}
     */
    public static function importRaw(int $episodeId, int $userId, string $raw, ?string $ip = null): array
    {
        $ep = self::episode($episodeId);
        if ($ep === null) {
            throw new \InvalidArgumentException('剧集不存在');
        }
        if ($userId <= 0) {
            throw new \InvalidArgumentException('导入必须指定归属用户（用 --user=<管理员id>）');
        }

        $parsed = self::parse($raw);
        $max    = self::maxMs($ep);
        $rows   = [];
        $skip   = 0;
        $errors = [];

        foreach ($parsed as $i => $p) {
            $text = self::cleanText((string) ($p['text'] ?? ''));
            if ($text === '') {
                $skip++;
                if (count($errors) < 5) {
                    $errors[] = '第 ' . ($i + 1) . ' 条文本为空，已跳过';
                }
                continue;
            }
            if (mb_strlen($text) > self::MAX_TEXT) {
                $text = mb_substr($text, 0, self::MAX_TEXT);
            }

            $tMs = (int) round(((float) ($p['t'] ?? 0)) * 1000);
            if ($tMs < 0 || $tMs > $max) {
                $skip++;
                if (count($errors) < 5) {
                    $errors[] = sprintf('第 %d 条时间 %.1fs 超出片长，已跳过', $i + 1, $tMs / 1000);
                }
                continue;
            }

            $rows[] = [
                'episode_id' => $episodeId,
                'user_id'    => $userId,
                't_ms'       => $tMs,
                'mode'       => in_array((int) ($p['mode'] ?? 1), self::MODES, true) ? (int) $p['mode'] : 1,
                'color'      => self::normalizeColor($p['color'] ?? 0xFFFFFF),
                'size'       => in_array((int) ($p['size'] ?? 1), [0, 1, 2], true) ? (int) $p['size'] : 1,
                'text'       => $text,
                'source'     => 'import',
                'ip'         => $ip,
            ];
        }

        // 去重：同一集内 (t_ms + 文本) 相同只留一条
        $seen   = [];
        $dedup  = [];
        foreach ($rows as $r) {
            $k = $r['t_ms'] . '|' . $r['text'];
            if (isset($seen[$k])) {
                $skip++;
                continue;
            }
            $seen[$k] = true;
            $dedup[]  = $r;
        }
        $rows = $dedup;

        $inserted = 0;
        foreach (array_chunk($rows, self::IMPORT_CHUNK) as $chunk) {
            $holders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?)'));
            $bind    = [];
            foreach ($chunk as $r) {
                foreach (['episode_id', 'user_id', 't_ms', 'mode', 'color', 'size', 'text', 'source', 'ip'] as $k) {
                    $bind[] = $r[$k];
                }
            }
            Database::run(
                'INSERT INTO `danmaku`
                   (`episode_id`,`user_id`,`t_ms`,`mode`,`color`,`size`,`text`,`source`,`ip`)
                 VALUES ' . $holders,
                $bind
            );
            $inserted += count($chunk);
        }

        Log::write('danmaku', '导入弹幕', [
            'episode' => $episodeId, 'user' => $userId,
            'parsed' => count($parsed), 'inserted' => $inserted, 'skipped' => $skip,
        ]);

        return [
            'parsed'   => count($parsed),
            'inserted' => $inserted,
            'skipped'  => $skip,
            'errors'   => $errors,
        ];
    }

    /**
     * 解析成统一中间结构 [{t:秒, mode:int, color:int, size:int, text:string}, ...]
     *
     * @return array<int,array{t:float,mode:int,color:int,size:int,text:string}>
     */
    public static function parse(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        // B 站 XML：<d p="…">文本</d>。用正则而不是 SimpleXML —— 弹幕文本里
        // 混着未转义的 & 和换行是常态，正规 XML 解析器会直接报错。
        if (str_contains($raw, '<d ')) {
            $out = [];
            if (preg_match_all('#<d\s+p="([^"]*)"[^>]*>(.*?)</d>#su', $raw, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    $p = explode(',', $x[1]);
                    if (count($p) < 4) {
                        continue;
                    }
                    $out[] = [
                        't'     => (float) $p[0],
                        'mode'  => (int) $p[1],
                        'size'  => self::mapExternalSize((int) $p[2]),
                        'color' => self::normalizeColor((int) $p[3]),
                        'text'  => html_entity_decode(strip_tags($x[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    ];
                }
            }
            return $out;
        }

        $j = json_decode($raw, true);
        if (!is_array($j)) {
            return [];
        }
        if (isset($j['items']) && is_array($j['items'])) {
            $j = $j['items'];
        }

        $out = [];
        foreach ($j as $it) {
            if (!is_array($it)) {
                continue;
            }
            if (array_is_list($it)) {
                // 数组形式固定为 [秒, 模式, 颜色, 文本, 字号?]，位置不能含糊
                $out[] = [
                    't'     => (float) ($it[0] ?? 0),
                    'mode'  => (int) ($it[1] ?? 1),
                    'color' => self::normalizeColor($it[2] ?? 0xFFFFFF),
                    'text'  => (string) ($it[3] ?? ''),
                    'size'  => (int) ($it[4] ?? 1),
                ];
                continue;
            }
            $out[] = [
                't'     => (float) ($it['t'] ?? $it['time'] ?? 0),
                'mode'  => (int) ($it['mode'] ?? 1),
                'color' => self::normalizeColor($it['color'] ?? 0xFFFFFF),
                'size'  => (int) ($it['size'] ?? 1),
                'text'  => (string) ($it['text'] ?? $it['content'] ?? ''),
            ];
        }
        return $out;
    }

    /** 外部字号 → 本站三档：≤18 小 / ≤25 中 / 其它 大（B 站用 18/25/45） */
    private static function mapExternalSize(int $s): int
    {
        if ($s <= 0) {
            return 1;
        }
        if ($s <= 18) {
            return 0;
        }
        return $s <= 25 ? 1 : 2;
    }
}
