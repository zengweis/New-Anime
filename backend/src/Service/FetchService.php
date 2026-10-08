<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Database;
use App\Core\Log;
use App\Core\Setting;

/**
 * RSS 抓取入库。幂等：靠 rss_item.guid 唯一键去重。
 */
final class FetchService
{
    /**
     * @return array<string,mixed>
     */
    public function run(?int $feedId = null): array
    {
        $stats = ['feeds' => 0, 'fetched' => 0, 'new' => 0, 'filtered' => 0, 'errors' => []];

        $feeds = $feedId !== null
            ? Database::all('SELECT * FROM `rss_feed` WHERE `id` = :id', ['id' => $feedId])
            : Database::all('SELECT * FROM `rss_feed` WHERE `enabled` = 1');

        if (!$feeds) {
            $stats['errors'][] = '没有启用的 RSS 源';
            return $stats;
        }

        foreach ($feeds as $f) {
            $stats['feeds']++;
            try {
                $items = DmhyRss::fetch((string) $f['url']);
            } catch (\Throwable $e) {
                $stats['errors'][] = $f['name'] . ': ' . $e->getMessage();
                Log::write('fetch', '抓取失败 ' . $f['name'] . ': ' . $e->getMessage());
                continue;
            }

            $stats['fetched'] += count($items);
            $added = 0;

            foreach ($items as $it) {
                if (!$this->passFilter($it['title'], $f)) {
                    $stats['filtered']++;
                    continue;
                }
                if ($this->store($it, (int) $f['id'])) {
                    $added++;
                }
            }

            $stats['new'] += $added;

            Database::update('rss_feed', [
                'last_fetch' => date('Y-m-d H:i:s'),
                'last_count' => count($items),
            ], '`id` = :id', ['id' => (int) $f['id']]);
        }

        $stats['errors'] = array_slice(array_unique($stats['errors']), 0, 10);
        Log::write('fetch', 'RSS 抓取完成', $stats);

        return $stats;
    }

    /**
     * 抓取阶段的基础过滤（源级）。
     */
    private function passFilter(string $title, array $feed): bool
    {
        $inc = trim((string) ($feed['filter_re'] ?? ''));
        if ($inc !== '') {
            $re = MatchEngine::compile($inc, 'iu');
            if ($re === null || !preg_match($re, $title)) {
                return false;
            }
        }
        $exc = trim((string) ($feed['exclude_re'] ?? ''));
        if ($exc !== '') {
            $re = MatchEngine::compile($exc, 'iu');
            if ($re !== null && preg_match($re, $title)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 写入一条 RSS 条目。返回是否新增。
     */
    private function store(array $it, int $feedId): bool
    {
        $title  = (string) $it['title'];
        $magnet = (string) ($it['magnet'] ?? '');
        $parsed = TitleParser::parse($title);
        $hash   = $magnet !== '' ? TitleParser::infohashFromMagnet($magnet) : null;

        $st = Database::run(
            'INSERT IGNORE INTO `rss_item`
               (`feed_id`,`guid`,`title`,`magnet`,`infohash`,`detail_url`,`filesize`,
                `subgroup`,`ep_no`,`resolution`,`subtitle_lang`,`pub_date`,`status`)
             VALUES
               (:feed,:guid,:title,:magnet,:hash,:url,:size,
                :sg,:ep,:res,:lang,:pub,0)',
            [
                'feed'   => $feedId,
                'guid'   => $it['guid'],
                'title'  => $title,
                'magnet' => $magnet !== '' ? $magnet : null,
                'hash'   => $hash,
                'url'    => $it['detail_url'] ?? null,
                'size'   => (int) ($it['filesize'] ?? 0),
                'sg'     => $parsed['subgroup'],
                'ep'     => $parsed['ep_no'],
                'res'    => $parsed['resolution'],
                'lang'   => $parsed['subtitle_lang'],
                'pub'    => $it['pub_date'] ?? null,
            ]
        );

        return $st->rowCount() > 0;
    }

    /** 抓取 + 自动加种 一条龙（cron 用） */
    public function fetchAndDownload(?int $feedId = null, int $dlLimit = 200): array
    {
        $fetch = $this->run($feedId);
        $auto  = ['scanned' => 0, 'added' => 0, 'skipped' => 0];

        // 不限「本轮有新条目」才跑：规则是后来才写的，
        // 光靠新条目触发会让「保存规则后自动追更」时灵时不灵。
        if (Setting::get('auto_fetch', '1') === '1') {
            $auto = (new AutoDownloader())->run($dlLimit);
        }

        return ['fetch' => $fetch, 'auto' => $auto];
    }

    /** 日志表写入 */
    public static function logJob(string $job, bool $ok, int $newCount, string $message, int $costMs): void
    {
        Database::insert('job_log', [
            'job'       => $job,
            'ok'        => $ok ? 1 : 0,
            'new_count' => $newCount,
            'message'   => mb_substr($message, 0, 1000),
            'cost_ms'   => $costMs,
        ]);
    }
}
