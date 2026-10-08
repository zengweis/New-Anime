<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Database;
use App\Core\Log;
use App\Core\Setting;

/**
 * 同步器：qBittorrent 里已完成的任务 → anime / episode 表。
 * 幂等：同一 (infohash, ep_no, subgroup) 不会重复入库。
 */
final class SyncService
{
    private Qbittorrent $qb;
    private array $videoExt;

    public function __construct(?Qbittorrent $qb = null)
    {
        $this->qb = $qb ?? new Qbittorrent();
        $this->videoExt = array_map(
            'strtolower',
            Setting::list('video_ext', 'mp4,mkv,avi,webm,flv,ts,mov,m4v')
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function run(): array
    {
        $stats = [
            'torrents' => 0, 'finished' => 0, 'imported' => 0,
            'episodes' => 0, 'skipped' => 0, 'errors' => [],
        ];

        $category = (string) Setting::get('qb_category', 'anime');

        try {
            $list = $this->qb->torrents($category);
        } catch (\Throwable $e) {
            $stats['errors'][] = $e->getMessage();
            Log::write('sync', 'qB 不可达: ' . $e->getMessage());
            return $stats;
        }

        foreach ($list as $t) {
            $stats['torrents']++;
            if (!Qbittorrent::isFinished($t)) {
                continue;
            }
            $stats['finished']++;

            try {
                $r = $this->importTorrent($t, $stats);
                if ($r > 0) {
                    $stats['imported']++;
                    $stats['episodes'] += $r;
                }
            } catch (\Throwable $e) {
                $stats['errors'][] = mb_substr((string) ($t['name'] ?? '?') . ': ' . $e->getMessage(), 0, 300);
                Log::write('sync', '导入失败: ' . $e->getMessage(), ['hash' => $t['hash'] ?? '']);
            }
        }

        $stats['errors'] = array_slice(array_unique($stats['errors']), 0, 20);
        Log::write('sync', '同步完成', $stats);
        return $stats;
    }

    /**
     * 导入单个已完成任务，返回新增集数。
     */
    private function importTorrent(array $t, array &$stats): int
    {
        $hash     = (string) ($t['hash'] ?? '');
        $name     = (string) ($t['name'] ?? '');
        $savePath = rtrim((string) ($t['save_path'] ?? ''), '/');
        if ($hash === '' || $savePath === '') {
            return 0;
        }

        // 1) 挑出视频文件（只看真正下载完成的）
        $videos = [];
        foreach ($this->qb->files($hash) as $f) {
            $rel = (string) ($f['name'] ?? '');
            if ($rel === '') {
                continue;
            }
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if (!in_array($ext, $this->videoExt, true)) {
                continue;
            }
            if ((int) ($f['priority'] ?? 1) === 0) {
                continue; // 未选中下载
            }
            if (isset($f['progress']) && (float) $f['progress'] < 0.999) {
                continue; // 文件本身没下完（多文件种子部分下载）
            }
            $videos[] = ['rel' => $rel, 'size' => (int) ($f['size'] ?? 0)];
        }
        if (!$videos) {
            return 0;
        }

        // 2) 归属番剧
        $item    = Database::one(
            'SELECT * FROM `rss_item` WHERE `torrent_hash` = :h OR `infohash` = :h ORDER BY `id` DESC LIMIT 1',
            ['h' => $hash]
        );
        $animeId = $item['anime_id'] !== null ? (int) $item['anime_id'] : null;

        if ($animeId === null) {
            $m = MatchEngine::match($name, true);
            if ($m !== null) {
                $animeId = $m['anime_id'];
            }
        }
        if ($animeId === null) {
            // 无法归属：挂起，等人工在后台指派
            if ($item === null) {
                Database::run(
                    'INSERT INTO `rss_item` (`guid`,`title`,`magnet`,`infohash`,`status`,`message`,`torrent_hash`)
                     VALUES (:g, :t, NULL, :h, 1, :msg, :h)
                     ON DUPLICATE KEY UPDATE `id` = `id`',
                    [
                        'g'   => 'torrent:' . $hash,
                        't'   => mb_substr($name, 0, 500),
                        'h'   => $hash,
                        'msg' => '下载完成但未能归属番剧，请手动指派',
                    ]
                );
            }
            $stats['skipped']++;
            $stats['errors'][] = '未能归属番剧：' . mb_substr($name, 0, 80);
            return 0;
        }

        $animeRow = Database::one('SELECT `title`,`title_cn` FROM `anime` WHERE `id` = :id', ['id' => $animeId]);
        if ($animeRow === null) {
            return 0;
        }

        // 3) 逐文件入库
        $added    = 0;
        $realPath = $savePath;
        // save_path 可能是种子根目录的父级，qB 的 files.name 已含相对路径
        $seq = 0;

        foreach ($videos as $v) {
            $seq++;
            $rel  = $v['rel'];
            $full = $realPath . '/' . $rel;
            if (!is_file($full)) {
                // 本地文件已被「上传到对象存储后删除本地」清理掉：这是**预期状态**，
                // 不是错误。以前每轮同步都会把这条报成「文件不存在」，刷满日志，
                // 也让真正需要关注的文件缺失被淹没。
                $purged = Database::val(
                    'SELECT 1 FROM `episode`
                      WHERE `infohash` = :h AND `s3_key` IS NOT NULL AND `s3_purged_at` IS NOT NULL
                      LIMIT 1',
                    ['h' => $hash]
                );
                if ($purged === null) {
                    $stats['errors'][] = '文件不存在：' . mb_substr($full, -80);
                } else {
                    $stats['skipped']++;
                }
                continue;
            }
            $fileSize = $v['size'] > 0 ? $v['size'] : (int) @filesize($full);

            $baseName = basename($rel);
            $parsed   = TitleParser::parse($baseName);

            $epNo = $parsed['ep_no'];
            if ($epNo === null || $epNo <= 0) {
                $epNo = count($videos) > 1 ? (float) $seq : 0.0;
            }

            $subgroup = $parsed['subgroup'] ?: ($item['subgroup'] ?? null);
            $subgroup = $subgroup !== null ? mb_substr((string) $subgroup, 0, 100) : null;

            $st = Database::run(
                'INSERT IGNORE INTO `episode`
                   (`anime_id`,`ep_no`,`title`,`file_path`,`file_size`,`infohash`,
                    `subgroup`,`resolution`,`subtitle_lang`,`published_at`)
                 VALUES
                   (:aid,:ep,:title,:path,:size,:hash,
                    :sg,:res,:lang,:pub)',
                [
                    'aid'   => $animeId,
                    'ep'    => $epNo,
                    'title' => mb_substr($baseName, 0, 500),
                    'path'  => mb_substr($full, 0, 1000),
                    'size'  => $fileSize,
                    'hash'  => $hash,
                    'sg'    => $subgroup,
                    'res'   => $parsed['resolution'] ?: ($item['resolution'] ?? null),
                    'lang'  => $parsed['subtitle_lang'],
                    'pub'   => $item['pub_date'] ?? null,
                ]
            );

            if ($st->rowCount() > 0) {
                $added++;
            } else {
                $stats['skipped']++;
            }
        }

        if ($added > 0) {
            $this->refreshAnime($animeId);
            if ($item !== null) {
                Database::update('rss_item', [
                    'status'  => 2,
                    'message' => "已入库 {$added} 集",
                ], '`id` = :id', ['id' => (int) $item['id']]);
            }
        }

        return $added;
    }

    // ==================================================================
    //  下载完成后暂停做种
    // ==================================================================

    /**
     * 把「已下载完成且还在做种」的任务暂停掉，避免长期占满服务器上行带宽。
     *
     * 为什么用轮询而不是 qB 原生机制：
     *   qB 有「做种比率上限」这个原生设置，但它的语义是「传到 X 比率后停」，
     *   而我们要的是「下载完就停」；而且它会影响 qB 里的所有任务（不分分类）。
     *   qB 的 API 又没有「任务完成」事件回调，所以只能轮询。
     *   常驻进程里以 60 秒一轮检查，最多多传一分钟，代价可接受。
     *
     * 已经停掉的任务不会再调 API（否则每轮都白发一次请求）。
     *
     * 不影响其它环节：暂停的任务在 API 里依然能被列出、能读文件列表，
     * 而且 `isFinished()` 把 pausedUP/stoppedUP 都算作已完成，
     * 所以「同步入库 → remux → 上传对象存储」这条链路照常跑。
     *
     * @return array{paused:int,skipped:int,failed:int,checked:int}
     */
    public static function pauseFinished(): array
    {
        $out = ['paused' => 0, 'skipped' => 0, 'failed' => 0, 'checked' => 0];

        $qb = new Qbittorrent();
        try {
            $list = $qb->torrents((string) Setting::get('qb_category', 'anime'));
        } catch (\Throwable $e) {
            Log::write('sync', '暂停做种：qB 不可达 ' . $e->getMessage());
            return $out;
        }

        $targets = [];
        foreach ($list as $t) {
            $out['checked']++;
            if (!Qbittorrent::isFinished($t)) {
                continue;
            }
            $state = (string) ($t['state'] ?? '');
            // 已经停下的不用再管
            if (in_array($state, ['pausedUP', 'stoppedUP'], true)) {
                $out['skipped']++;
                continue;
            }
            // checkingUP / moving 是过渡态，等它变成做种态下一轮再处理
            if (in_array($state, ['checkingUP', 'moving'], true)) {
                $out['skipped']++;
                continue;
            }
            $hash = (string) ($t['hash'] ?? '');
            if ($hash !== '') {
                $targets[] = $hash;
            }
        }

        if (!$targets) {
            return $out;
        }

        $ok = $qb->pause($targets);
        if ($ok) {
            $out['paused'] = count($targets);
            Log::write('sync', '已完成任务暂停做种', ['count' => count($targets)]);
        } else {
            $out['failed'] = count($targets);
            Log::write('sync', '暂停做种失败', ['count' => count($targets)]);
        }
        return $out;
    }

    /** 回填番剧的集数统计 */
    public function refreshAnime(int $animeId): void
    {        $agg = Database::one(
            'SELECT COUNT(*) AS c, MAX(`ep_no`) AS m FROM `episode` WHERE `anime_id` = :id',
            ['id' => $animeId]
        );
        Database::update('anime', [
            'ep_count'   => (int) ($agg['c'] ?? 0),
            'last_ep'    => $agg['m'] ?? null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], '`id` = :id', ['id' => $animeId]);
    }

    /** 批量回填（后台修复用） */
    public function refreshAll(): int
    {
        $ids = Database::all('SELECT `id` FROM `anime`');
        foreach ($ids as $r) {
            $this->refreshAnime((int) $r['id']);
        }
        return count($ids);
    }
}
