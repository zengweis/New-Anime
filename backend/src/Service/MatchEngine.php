<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Database;

/**
 * 正则匹配引擎：标题 → 番剧。
 *
 * 规则由管理员在后台为每部番手工录入，命中即归入该番。
 */
final class MatchEngine
{
    /** 编译后的规则缓存（同进程内） */
    private static array $compiled = [];

    /**
     * 把用户输入的正则规范化为完整的 PCRE，失败返回 null。
     * 支持两种写法：  foo.*bar   |   /foo.*bar/iu
     */
    public static function compile(string $pattern, string $flags = 'iu'): ?string
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return null;
        }

        // 已是 /.../flags 形式
        if (strlen($pattern) > 2 && $pattern[0] === '/') {
            $lastSlash = strrpos($pattern, '/');
            if ($lastSlash > 0) {
                $body  = substr($pattern, 1, $lastSlash - 1);
                $mods  = substr($pattern, $lastSlash + 1);
                $mods  = preg_replace('/[^a-zA-Z]/', '', $mods) ?? '';
                if (!str_contains($mods, 'u')) {
                    $mods .= 'u';
                }
                $re = '/' . $body . '/' . $mods;
                return @preg_match($re, '') === false ? null : $re;
            }
        }

        $mods = preg_replace('/[^a-zA-Z]/', '', $flags) ?: 'iu';
        if (!str_contains($mods, 'u')) {
            $mods .= 'u';
        }
        // 只转义「裸 #」，已经是 \# 的保持原样——
        // 否则 preg_quote($s, '#') 产出的 \# 会被二次转义成 \\#，把定界符提前闭合。
        $body = preg_replace('/(?<!\\\\)#/', '\\#', $pattern) ?? $pattern;
        $re = '#' . $body . '#' . $mods;

        return @preg_match($re, '') === false ? null : $re;
    }

    public static function validate(string $pattern, string $flags = 'iu'): ?string
    {
        if (trim($pattern) === '') {
            return '正则不能为空';
        }
        if (self::compile($pattern, $flags) === null) {
            return '正则语法错误，请检查括号与转义字符';
        }
        return null;
    }

    /** 加载全部启用规则（带缓存） */
    private static function rules(bool $fresh = false): array
    {
        if (!$fresh && isset(self::$compiled['__rules'])) {
            return self::$compiled['__rules'];
        }
        $rules = Database::all(
            'SELECT r.`id`, r.`anime_id`, r.`pattern`, r.`flags`, r.`ep_capture`, r.`priority`,
                    r.`auto_download`,
                    a.`title` AS anime_title, a.`title_cn` AS anime_title_cn
             FROM `match_rule` r
             JOIN `anime` a ON a.`id` = r.`anime_id`
             WHERE r.`enabled` = 1
             ORDER BY r.`priority` DESC, r.`id` ASC'
        );
        foreach ($rules as &$r) {
            $r['__re'] = self::compile((string) $r['pattern'], (string) $r['flags']);
        }
        unset($r);
        self::$compiled['__rules'] = $rules;
        return $rules;
    }

    public static function flush(): void
    {
        self::$compiled = [];
    }

    /**
     * 对单个标题跑规则，返回首个命中（优先级最高）。
     *
     * @return array{rule_id:int,anime_id:int,anime_title:string,ep_no:?float,auto_download:bool,captures:array}|null
     */
    public static function match(string $title, bool $fresh = false): ?array
    {
        foreach (self::rules($fresh) as $r) {
            if ($r['__re'] === null) {
                continue;
            }
            if (!preg_match($r['__re'], $title, $m)) {
                continue;
            }
            $ep  = null;
            $cap = (int) $r['ep_capture'];
            if ($cap > 0 && isset($m[$cap]) && is_numeric($m[$cap])) {
                $ep = (float) $m[$cap];
            }
            if ($ep === null || $ep <= 0) {
                $ep = TitleParser::epNo($title);
            }

            return [
                'rule_id'       => (int) $r['id'],
                'anime_id'      => (int) $r['anime_id'],
                'anime_title'   => (string) ($r['anime_title_cn'] ?: $r['anime_title']),
                'ep_no'         => $ep,
                'auto_download' => (int) ($r['auto_download'] ?? 1) === 1,
                'captures'      => array_slice($m, 0, 6),
            ];
        }
        return null;
    }

    /** 命中计数 +1 */
    public static function bumpHit(int $ruleId): void
    {
        Database::run('UPDATE `match_rule` SET `hits` = `hits` + 1 WHERE `id` = :id', ['id' => $ruleId]);
    }

    /**
     * 可视化筛选台：拿一批最近 RSS 条目，实时测试正则命中情况。
     *
     * @return array{pattern:string,total:int,hit:int,items:array}
     */
    public static function preview(string $pattern, string $flags = 'iu', int $limit = 200, int $epCapture = 1): array
    {
        $re = self::compile($pattern, $flags);
        if ($re === null) {
            throw new \RuntimeException('正则语法错误');
        }

        $limit = max(1, min(500, $limit));
        $items = Database::all(
            "SELECT `id`, `title`, `status`, `anime_id`, `pub_date`, `subgroup`, `resolution`
             FROM `rss_item`
             ORDER BY `pub_date` DESC, `id` DESC
             LIMIT {$limit}"
        );

        $out = [];
        $hit = 0;
        foreach ($items as $it) {
            $matched = (bool) preg_match($re, (string) $it['title'], $m);
            $ep      = null;
            if ($matched) {
                $hit++;
                $capVal = $m[$epCapture] ?? null;
                if (is_numeric($capVal)) {
                    $ep = (float) $capVal;
                } else {
                    $ep = TitleParser::epNo((string) $it['title']);
                }
            }
            $out[] = [
                'id'       => (int) $it['id'],
                'title'    => (string) $it['title'],
                'matched'  => $matched,
                'captures' => $matched ? array_slice($m, 1, 4) : [],
                'ep_no'    => $ep,
                'status'   => (int) $it['status'],
                'anime_id' => $it['anime_id'] !== null ? (int) $it['anime_id'] : null,
            ];
        }

        return [
            'pattern'   => $pattern,
            'compiled'  => $re,
            'total'     => count($out),
            'hit'       => $hit,
            'items'     => $out,
        ];
    }

    /**
     * 根据一条真实标题，自动生成正则草稿。
     *
     * @return array{pattern:string,note:string,subgroup:?string,title_key:string,ep_no:?float}
     */
    public static function suggest(string $title): array
    {
        $p  = TitleParser::parse($title);
        $sg = (string) ($p['subgroup'] ?? '');
        $tk = (string) ($p['title_key'] ?? '');

        $parts = [];
        if ($sg !== '') {
            $parts[] = preg_quote($sg, '#');
        }
        if ($tk !== '') {
            // 主标题里最长的中文/英文片段作为识别锚点
            $anchor = self::longestAnchor($tk);
            if ($anchor !== '') {
                $parts[] = preg_quote($anchor, '#');
            }
        }
        $parts[] = '(\\d{1,3})';

        return [
            'pattern'   => implode('.*', $parts),
            'note'      => '按「字幕组 + 标题锚点 + 集号」生成，集号在第 1 个捕获组',
            'subgroup'  => $sg ?: null,
            'title_key' => $tk,
            'ep_no'     => $p['ep_no'],
        ];
    }

    private static function longestAnchor(string $s): string
    {
        // 取最长的连续中文段（>=2 字），没有则取最长的英文单词
        $best = '';
        if (preg_match_all('/[\p{Han}]{2,}/u', $s, $m)) {
            foreach ($m[0] as $seg) {
                if (mb_strlen($seg) > mb_strlen($best)) {
                    $best = $seg;
                }
            }
        }
        if ($best === '' && preg_match_all('/[A-Za-z]{3,}/', $s, $m2)) {
            foreach ($m2[0] as $seg) {
                if (strlen($seg) > strlen($best)) {
                    $best = $seg;
                }
            }
        }
        return $best;
    }
}
