<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Http;

/**
 * dmhy.org RSS 抓取与解析。
 */
final class DmhyRss
{
    public const SORT_ANIME = 2;      // 動畫
    public const SORT_ALL   = 0;

    public const BASE = 'https://dmhy.org';

    /**
     * 构造 RSS 地址。
     *
     * @param string $keyword 关键词，空则拉全量
     * @param int    $sortId  分类，2=動畫
     * @param int    $teamId  字幕组 ID，0=不限
     */
    public static function url(string $keyword = '', int $sortId = self::SORT_ANIME, int $teamId = 0): string
    {
        $path = $sortId > 0
            ? '/topics/rss/sort_id/' . $sortId . '/rss.xml'
            : '/topics/rss/rss.xml';

        $q = [];
        if ($keyword !== '') {
            $q['keyword'] = $keyword;
        }
        if ($teamId > 0) {
            $q['team_id'] = $teamId;
        }
        return self::BASE . $path . ($q ? '?' . http_build_query($q) : '');
    }

    /**
     * 抓取并解析。
     *
     * @return array<int,array<string,mixed>>
     */
    public static function fetch(string $url, int $timeout = 25): array
    {
        $r = Http::get($url, ['timeout' => $timeout, 'retry' => 2, 'follow' => true]);

        if (!$r['ok']) {
            throw new \RuntimeException(sprintf(
                'RSS 抓取失败 HTTP %d %s (%s)',
                $r['status'],
                $r['error'],
                $url
            ));
        }
        $body = $r['body'];
        if ($body === '') {
            throw new \RuntimeException('RSS 返回空内容: ' . $url);
        }
        if (stripos($body, '<rss') === false && stripos($body, '<feed') === false) {
            $hint = mb_substr(strip_tags($body), 0, 120);
            throw new \RuntimeException('RSS 返回的不是 XML（可能被 WAF 拦截）: ' . $hint);
        }
        return self::parse($body);
    }

    /**
     * 解析 RSS / Atom XML。
     *
     * @return array<int,array{
     *   guid:string,title:string,magnet:?string,detail_url:string,
     *   pub_date:?string,filesize:int
     * }>
     */
    public static function parse(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc  = new \DOMDocument();
        $ok   = $doc->loadXML($xml, LIBXML_NOCDATA | LIBXML_NOENT | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$ok) {
            throw new \RuntimeException('RSS XML 解析失败');
        }

        $xp    = new \DOMXPath($doc);
        $nodes = $xp->query('//item');
        if ($nodes === false || $nodes->length === 0) {
            $nodes = $xp->query('//entry'); // Atom 兼容
        }

        $out = [];
        foreach ($nodes as $node) {
            /** @var \DOMElement $node */
            $get = static function (string $tag) use ($xp, $node): string {
                $n = $xp->query($tag, $node)->item(0);
                return $n ? trim($n->textContent) : '';
            };

            $title = $get('title');
            if ($title === '') {
                continue;
            }

            $link = $get('link');
            if ($link === '') {
                $alt = $xp->query('link[@rel="alternate"]', $node)->item(0);
                if ($alt instanceof \DOMElement) {
                    $link = $alt->getAttribute('href');
                }
            }

            $guid = $get('guid');
            if ($guid === '') {
                $guid = $get('id');
            }
            $detailUrl = $guid !== '' && str_starts_with($guid, 'http') ? $guid : $link;
            if ($detailUrl === '') {
                $detailUrl = $guid;
            }
            // 去重键优先用详情页 URL（dmhy 的 guid 有重复风险）
            $dedupKey = $detailUrl !== '' ? $detailUrl : md5($title);

            // 磁力链接：优先 enclosure，退而求其次从 description 里捞
            $magnet = '';
            $enc = $xp->query('enclosure', $node)->item(0);
            if ($enc instanceof \DOMElement) {
                $u = $enc->getAttribute('url');
                if (stripos($u, 'magnet:') === 0) {
                    $magnet = $u;
                }
            }
            if ($magnet === '') {
                $blob = $get('description') . ' ' . $title . ' ' . $link;
                if (preg_match('/magnet:\?xt=urn:btih:[a-zA-Z0-9]{32,40}[^"\'<>\s]*/i', $blob, $m)) {
                    $magnet = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }

            $pubRaw = $get('pubDate') ?: $get('updated') ?: $get('published');
            $pub    = $pubRaw !== '' ? strtotime($pubRaw) : false;

            $size = 0;
            if ($enc instanceof \DOMElement && $enc->getAttribute('length') !== '') {
                $size = (int) $enc->getAttribute('length');
            }

            $out[] = [
                'guid'       => mb_substr($dedupKey, 0, 191),
                'title'      => mb_substr($title, 0, 500),
                'magnet'     => $magnet !== '' ? mb_substr($magnet, 0, 1000) : null,
                'detail_url' => mb_substr($link !== '' ? $link : $detailUrl, 0, 500),
                'pub_date'   => $pub ? date('Y-m-d H:i:s', $pub) : null,
                'filesize'   => $size,
            ];
        }

        return $out;
    }
}
