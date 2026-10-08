<?php
declare(strict_types=1);

namespace App\Service;

/**
 * dmhy 发布标题解析器。
 *
 * 典型输入：
 *   [桜都字幕组][4月新番] 某番剧 / Some Anime [01][1080P][简体内嵌]
 *   【喵萌奶茶屋】★04月新番★[Some Anime][03][1080p][简日双语][招募翻译]
 *   [SubsPlease] Some Anime - 07 (1080p) [A1B2C3D4].mkv
 */
final class TitleParser
{
    /** 集号匹配模式，按优先级排列。捕获组必须把 .5 小数部分一起吃进去 */
    private const EP_PATTERNS = [
        // [01] [01v2] [01.5] [第01话]
        '/\[(?:第)?(\d{1,4}(?:\.5)?)(?:v\d+)?(?:话|話|集)?\]/u',
        // 第01话 / 第 01 集 / 第01話
        '/第\s*(\d{1,4}(?:\.5)?)\s*(?:话|話|集|回)/u',
        // EP01 / E01 / Ep.01
        '/(?:^|[\s\-–—_\[\]])(?:EP|Ep|ep|E)\.?\s*(\d{1,4}(?:\.5)?)(?=[\s\-–—\[\]\(\)]|$)/u',
        // " - 07" 结尾形式
        '/\s-\s(\d{1,4}(?:\.5)?)(?:v\d+)?(?=[\s\-–—\[\]\(\)\.]|$)/u',
        // 兜底：独立的两位数
        '/(?:^|[\s\-–—_\[\]])(\d{2,4}(?:\.5)?)(?:v\d+)?(?=[\s\-–—_\[\]\(\)]|$)/u',
    ];

    /**
     * @return array{
     *   subgroup:?string, ep_no:?float, resolution:?string,
     *   subtitle_lang:?string, title_key:string, is_batch:bool, season:?int
     * }
     */
    public static function parse(string $title): array
    {
        $t = str_replace(["\u{3000}"], ' ', $title);
        $t = trim($t);

        return [
            'subgroup'      => self::subgroup($t),
            'ep_no'         => self::epNo($t),
            'resolution'    => self::resolution($t),
            'subtitle_lang' => self::subtitleLang($t),
            'title_key'     => self::titleKey($t),
            'is_batch'      => self::isBatch($t),
            'season'        => self::season($t),
        ];
    }

    /** 字幕组：第一个方括号/书名号内容（排除"新番""合集"等噪声） */
    public static function subgroup(string $t): ?string
    {
        if (preg_match('/^[\[【]([^\]】]{1,60})[\]】]/u', $t, $m)) {
            $s = trim($m[1]);
            $noise = ['新番', '動畫', '动画', '合集', '招募', '字幕组', '字幕組'];
            if ($s !== '' && !in_array($s, $noise, true) && !preg_match('/^\d+月$/', $s)) {
                return $s;
            }
        }
        // 【喵萌奶茶屋】 ★04月新番★ 这类
        if (preg_match('/[\[【]([\p{Han}A-Za-z0-9\-_ ]{2,30}(?:字幕组|字幕組|Sub|Raws|字幕社|工作室))[\]】]/u', $t, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /** 集号：0 表示合集/未知。返回 float 以支持 1.5 这种特别篇 */
    public static function epNo(string $t): ?float
    {
        // 合集优先判定：[01-12] / 全12话
        if (self::isBatch($t)) {
            return 0.0;
        }
        foreach (self::EP_PATTERNS as $re) {
            if (preg_match($re, $t, $m)) {
                $n = (float) $m[1];
                // 排除明显是年份的
                if ($n >= 1900 && $n <= 2100) {
                    continue;
                }
                if ($n <= 0 || $n > 2000) {
                    continue;
                }
                return $n;
            }
        }
        return null;
    }

    public static function resolution(string $t): ?string
    {
        if (preg_match('/\b(4320|2160|1440|1080|720|576|480)[pPiI]\b/u', $t, $m)) {
            return $m[1] . 'P';
        }
        if (preg_match('/\b(8K|4K|2K)\b/iu', $t, $m)) {
            return strtoupper($m[1]) === '4K' ? '2160P' : strtoupper($m[1]);
        }
        if (preg_match('/\bFHD\b/iu', $t)) {
            return '1080P';
        }
        if (preg_match('/\bHD\b/iu', $t)) {
            return '720P';
        }
        return null;
    }

    /** 归一化：简 / 繁 / 简繁 / 日 / 外挂 / null */
    public static function subtitleLang(string $t): ?string
    {
        $s = mb_strtolower($t);

        // 同时标注简繁（「简繁」「簡繁」「CHS&CHT」）优先判定，避免被简/繁单边吃掉
        if (preg_match('/简繁|簡繁|繁简|chs\s*[&+＆]\s*cht|简繁双语|简繁字幕/iu', $s)) {
            return '简繁';
        }

        $hasSimp = (bool) preg_match('/简体|简中|简日|簡体|chs|gb\b|sc\b|chinese\s*simplified/iu', $s);
        $hasTrad = (bool) preg_match('/繁体|繁中|繁日|繁體|cht|big5|tc\b|chinese\s*traditional/iu', $s);

        if ($hasSimp && $hasTrad) {
            return '简繁';
        }
        if ($hasSimp) {
            return '简';
        }
        if ($hasTrad) {
            return '繁';
        }
        if (preg_match('/外挂|字幕文件|ass\b|srt\b/iu', $s)) {
            return '外挂';
        }
        if (preg_match('/生肉|无字幕|no\s*sub|raw\b|日语|japanese/iu', $s)) {
            return '日';
        }
        return null;
    }

    /** 是否合集 / 季度全话 */
    public static function isBatch(string $t): bool
    {
        if (preg_match('/\[(\d{1,3})\s*[-~～]\s*(\d{1,3})\]|全\s*\d{1,3}\s*[话話集]|完结|合集|BDRip|BD\s*BOX/iu', $t)) {
            return true;
        }
        return false;
    }

    /**
     * 归一化主标题：剥掉字幕组、分辨率、集号、语言标记、编码信息，用于人工确认/辅助匹配。
     */
    public static function titleKey(string $t): string
    {
        $s = $t;

        // 去掉文件名后缀
        $s = preg_replace('/\.(mkv|mp4|avi|webm|ts|flv|mov|torrent)$/iu', '', $s);
        // 先摘掉开头的字幕组标记（subgroup 已单独解析过）
        $s = preg_replace('/^\s*[\[【][^\]】]{1,60}[\]】]\s*/u', ' ', $s);
        // 其余括号只脱去括号、保留内容 —— dmhy 有大量 "[组][标题/原名][01]" 全括号写法
        $s = preg_replace('/[\[【]\s*([^\]】]*?)\s*[\]】]/u', ' $1 ', $s);
        // 去掉 1080p 之类的分辨率
        $s = preg_replace('/\b\d{3,4}[pPiI]\b|\b(?:4K|8K|FHD|HD)\b/iu', ' ', $s);
        // 去掉 x264/x265/HEVC/AVC/AAC/FLAC/10bit/8bit/CHS/JPN 等技技术标记
        $s = preg_replace(
            '/\b(?:x26[45]|h\.?26[45]|hevc|avc|aac|flac|opus|ac3|dts|ma\d?p?|10bit|8bit|hi10p|web-?dl|webrip|bluray|bdrip|hdtv|remux|repack|v\d)\b/iu',
            ' ',
            $s
        );
        // 去掉语言/字幕标记
        $s = preg_replace(
            '/简繁|简体|繁体|简中|繁中|简日|繁日|日语|中日|双语|内嵌|内封|外挂|字幕|招募|合集|新番|chs|cht|big5|gb|sc|tc|jpn|jap/iu',
            ' ',
            $s
        );
        // 去掉流媒体平台名与裸扩展名
        $s = preg_replace(
            '/\b(?:baha|bilibili|b-global|bglobal|crunchyroll|netflix|amazon|viu|muse|ani-?one|abema|dmm|hulu|disney|iqiyi|youku|wetv|mp4|mkv|avi|webm|mov|ass|srt)\b/iu',
            ' ',
            $s
        );
        // "4月新番" / "2026年7月番" 这类档期标记
        $s = preg_replace('/\d{1,2}\s*月(?:新番|番)?/u', ' ', $s);
        // 去掉集号残留
        $s = preg_replace('/第\s*\d{1,4}\s*[话話集回]/u', ' ', $s);
        $s = preg_replace('/(?:^|[\s\-–—_])(?:EP|Ep|E)\.?\s*\d{1,4}/u', ' ', $s);
        // "标题 - 10" 形式的集号
        $s = preg_replace('/\s[-–—]\s\d{1,4}(?:\.5)?(?:v\d+)?(?=\s|$)/u', ' ', $s);
        // 结尾孤立的集号数字
        $s = preg_replace('/\s\d{1,3}(?:\.5)?\s*$/u', ' ', $s);
        $s = preg_replace('/^\s*\d{1,3}(?:\.5)?\s+/u', ' ', $s);

        // 去掉各种星号、波浪线装饰
        $s = preg_replace('/[★☆~～\-–—_\/\\\\|+]+/u', ' ', $s);
        $s = preg_replace('/\s{2,}/u', ' ', $s);
        $s = trim($s, " \t\n\r\0\x0B·.、,，");

        // 全角转半角
        $s = mb_convert_kana($s, 'as', 'UTF-8');

        return mb_substr(trim($s), 0, 200);
    }

    /** 从 "4月新番" / "2026年10月" 里猜季度 */
    public static function season(string $t): ?int
    {
        if (preg_match('/(\d{1,2})\s*月(?:新番)?/u', $t, $m)) {
            $mo = (int) $m[1];
            if ($mo >= 1 && $mo <= 12) {
                return match (true) {
                    $mo <= 3  => 1,
                    $mo <= 6  => 2,
                    $mo <= 9  => 3,
                    default   => 4,
                };
            }
        }
        return null;
    }

    /** 从标题里抽年份 */
    public static function year(string $t): ?int
    {
        if (preg_match('/(20\d{2})\s*年/u', $t, $m)) {
            $y = (int) $m[1];
            return ($y >= 1990 && $y <= 2100) ? $y : null;
        }
        return null;
    }

    /**
     * 分辨率排序权重，用于"最低分辨率"过滤与优选。
     */
    public static function resWeight(?string $res): int
    {
        return match (strtoupper((string) $res)) {
            '4320P', '8K' => 4320,
            '2160P', '4K' => 2160,
            '1440P', '2K' => 1440,
            '1080P'       => 1080,
            '720P'        => 720,
            '576P'        => 576,
            '480P'        => 480,
            default       => 0,
        };
    }

    /** 从磁力链接里取 infohash（小写） */
    public static function infohashFromMagnet(string $magnet): ?string
    {
        if (preg_match('/xt=urn:btih:([a-zA-Z0-9]{32,40})/i', $magnet, $m)) {
            return self::normalizeInfohash($m[1]);
        }
        return null;
    }

    /**
     * 统一 infohash 表示。
     *
     * dmhy 的磁力链接用 Base32 编码（32 字符），而 qBittorrent 一律以 40 位小写十六进制
     * 回传任务。不归一化的话，`rss_item.torrent_hash` 与 qB 的任务列表永远对不上，
     * 下载完成了也同步不进来。
     */
    public static function normalizeInfohash(string $h): ?string
    {
        $h = trim($h);
        if (strlen($h) === 40 && preg_match('/^[0-9a-f]{40}$/i', $h)) {
            return strtolower($h);
        }
        if (strlen($h) === 32) {
            return self::base32ToHex($h);
        }
        return $h !== '' ? strtolower($h) : null;
    }

    /** Base32（RFC 4648 无填充，32 字符 = 160 bit）→ 40 位十六进制 */
    public static function base32ToHex(string $b32): ?string
    {
        $b32 = strtoupper(trim($b32));
        if (strlen($b32) !== 32) {
            return null;
        }
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        for ($i = 0; $i < 32; $i++) {
            $pos = strpos($alphabet, $b32[$i]);
            if ($pos === false) {
                return null;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $hex = '';
        for ($i = 0; $i < 40; $i++) {
            $hex .= dechex(bindec(substr($bits, $i * 4, 4)));
        }
        return $hex;
    }
}
