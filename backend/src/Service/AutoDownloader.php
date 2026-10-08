<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Database;
use App\Core\Log;
use App\Core\Setting;

/**
 * 加种器：RSS 条目 → 匹配规则 → 加进 qBittorrent。
 *
 * 三个入口共用同一套护栏（guardAndAdd）：
 *   run()                —— 常驻循环，处理「订阅源开了自动下载」的新条目
 *   downloadForRule()    —— 保存规则后 / 手动点「下载命中」，处理该番的待处理条目
 *   downloadOne()        —— 单条强制加种，管理员显式操作，不受护栏约束
 *
 * 护栏：分辨率下限 / 同番同集去重 / 每小时加种上限 / 磁力链接校验。
 */
final class AutoDownloader
{
    private Qbittorrent $qb;

    public function __construct(?Qbittorrent $qb = null)
    {
        $this->qb = $qb ?? new Qbittorrent();
    }

    /** 空统计 */
    private static function blankStats(): array
    {
        return [
            'scanned' => 0, 'matched' => 0, 'added' => 0,
            'skipped' => 0, 'failed' => 0, 'blocked' => 0,
            'notes'   => [],
        ];
    }

    /**
     * 组装护栏参数。$hourlyLimit 传 0 表示本批次不限速。
     *
     * @return array<string,mixed>
     */
    private function guardOptions(): array
    {
        return [
            'dedupe'       => Setting::get('dedupe_by_ep', '1') === '1',
            'min_res'      => TitleParser::resWeight((string) Setting::get('min_resolution', '1080')),
            'hourly_limit' => (int) Setting::get('hourly_add_limit', 30),
            'save_path'    => (string) Setting::get('download_root', '/data/downloads/anime'),
            'category'     => (string) Setting::get('qb_category', 'anime'),
        ];
    }

    /** 本小时已加种数（护栏 3 的基数） */
    private static function addedThisHour(): int
    {
        return (int) Database::val(
            'SELECT COUNT(*) FROM `rss_item`
             WHERE `status` = 1 AND `added_at` IS NOT NULL
               AND `added_at` > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
    }

    // ==================================================================
    //  常驻循环
    // ==================================================================

    /**
     * 常驻循环：扫描待处理条目，命中「开了自动下载的规则」就加种。
     *
     * 判定顺序：命中规则 → 看规则的 auto_download 开关；
     * 源级 auto_down 作为并列的放行条件（整源纳入自动加种）。
     * 两者都不满足则只归类、不加种，等人工在后台点「下载命中」。
     *
     * @return array<string,mixed>
     */
    public function run(int $limit = 300): array
    {
        $stats = self::blankStats();
        $opt   = $this->guardOptions();

        // 新的一批待处理条目：按发布时间倒序，新番新集优先——
        // 顺序遍历会让老条目一直占满 LIMIT，新集永远轮不到。
        $items = Database::all(
            'SELECT i.*, f.`auto_down` AS `feed_auto_down`
             FROM `rss_item` i
             LEFT JOIN `rss_feed` f ON f.`id` = i.`feed_id`
             WHERE i.`status` = 0
             ORDER BY i.`pub_date` DESC, i.`id` DESC
             LIMIT ' . max(1, $limit)
        );

        if (!$items) {
            return $stats;
        }

        $added    = self::addedThisHour();
        $catReady = false;

        foreach ($items as $it) {
            $stats['scanned']++;
            $title = (string) $it['title'];

            $m = MatchEngine::match($title);
            if ($m === null) {
                continue; // 未命中任何规则，留在池子里等人工建规则
            }
            $stats['matched']++;

            // 规则没开自动下载，且该源也没整体开启 → 只归类，不加种
            $allowRule = !empty($m['auto_download']);
            $allowFeed = $it['feed_id'] === null || (int) $it['feed_auto_down'] === 1;
            if (!$allowRule && !$allowFeed) {
                Database::update('rss_item', [
                    'anime_id'        => $m['anime_id'],
                    'matched_rule_id' => $m['rule_id'],
                    'ep_no'           => $m['ep_no'],
                    'message'         => '已归类，未开启自动下载',
                ], '`id` = :rid', ['rid' => (int) $it['id']]);
                MatchEngine::bumpHit($m['rule_id']);
                $stats['skipped']++;
                continue;
            }

            $verdict = $this->guardAndAdd($it, $m, $opt, $stats, $catReady, $added);
            if ($verdict === 'limit') {
                break; // 保留 status=0，下一轮继续
            }
        }

        $stats['notes'] = array_slice(array_unique($stats['notes']), 0, 20);
        Log::write('autodl', '自动加种完成', $stats);

        return $stats;
    }

    // ==================================================================
    //  按番剧 / 按规则下载（保存规则后触发，或后台手动点）
    // ==================================================================

    /** 规则 → 所属番剧 ID；规则不存在返回 null */
    public static function ruleAnimeId(int $ruleId): ?int
    {
        $v = Database::val('SELECT `anime_id` FROM `match_rule` WHERE `id` = :id', ['id' => $ruleId]);
        return $v === null ? null : (int) $v;
    }

    /**
     * 保存规则后 / 手动按钮：把该番「待处理」的条目加进下载队列。
     *
     * 与 run() 的区别：不受订阅源 auto_down 开关限制——这是管理员的显式动作。
     * 仍然走全部护栏，所以重复点不会重复加种。
     *
     * @return array<string,mixed>
     */
    public function downloadForAnime(int $animeId, int $limit = 100): array
    {
        $stats = self::blankStats();

        if ($animeId <= 0) {
            $stats['notes'][] = '番剧不存在';
            return $stats;
        }

        $items = Database::all(
            'SELECT * FROM `rss_item`
             WHERE `status` = 0 AND `anime_id` = :aid
             ORDER BY `pub_date` ASC, `id` ASC
             LIMIT ' . max(1, $limit),
            ['aid' => $animeId]
        );

        if (!$items) {
            return $stats;
        }

        $opt   = $this->guardOptions();
        $added = self::addedThisHour();
        $catReady = false;

        foreach ($items as $it) {
            $stats['scanned']++;

            // 条目已归类，但还是要跑一次规则：一是取集号，二是确认它仍属于本番
            $m = MatchEngine::match((string) $it['title']);
            if ($m === null || (int) $m['anime_id'] !== $animeId) {
                $m = [
                    'anime_id' => $animeId,
                    'rule_id'  => $it['matched_rule_id'] !== null ? (int) $it['matched_rule_id'] : null,
                    'ep_no'    => $it['ep_no'] !== null ? (float) $it['ep_no'] : TitleParser::epNo((string) $it['title']),
                ];
            }
            $stats['matched']++;

            $verdict = $this->guardAndAdd($it, $m, $opt, $stats, $catReady, $added);
            if ($verdict === 'limit') {
                $stats['notes'][] = '本小时已达加种上限，剩余条目排队等待';
                break;
            }
        }

        $stats['notes'] = array_slice(array_unique($stats['notes']), 0, 20);
        Log::write('autodl', "按番剧加种 #{$animeId}", $stats);

        return $stats;
    }

    /**
     * 按规则下载：解析出所属番剧后走 downloadForAnime。
     *
     * @return array<string,mixed>
     */
    public function downloadForRule(int $ruleId, int $limit = 100): array
    {
        $animeId = self::ruleAnimeId($ruleId);
        if ($animeId === null) {
            $s = self::blankStats();
            $s['notes'][] = '规则不存在';
            return $s;
        }
        return $this->downloadForAnime($animeId, $limit);
    }

    // ==================================================================
    //  单条强制加种
    // ==================================================================

    /** 单条手动加种（后台按钮用）。显式操作，不套护栏。 */
    public function downloadOne(int $itemId): array
    {
        $it = Database::one('SELECT * FROM `rss_item` WHERE `id` = :id', ['id' => $itemId]);
        if ($it === null) {
            return ['ok' => false, 'msg' => '条目不存在'];
        }
        $magnet = (string) ($it['magnet'] ?? '');
        if (stripos($magnet, 'magnet:') !== 0) {
            return ['ok' => false, 'msg' => '该条目没有磁力链接'];
        }

        $m     = MatchEngine::match((string) $it['title'], true);
        $anime = $m['anime_id'] ?? null;
        $rule  = $m['rule_id'] ?? null;
        $epNo  = $m['ep_no'] ?? TitleParser::epNo((string) $it['title']);

        $category = (string) Setting::get('qb_category', 'anime');
        $this->qb->ensureCategory($category);

        $r = $this->qb->add(
            $magnet,
            (string) Setting::get('download_root', '/data/downloads/anime'),
            $category
        );

        Database::update('rss_item', [
            'status'          => $r['ok'] ? 1 : 4,
            'torrent_hash'    => $r['hash'],
            'infohash'        => $r['hash'],
            'anime_id'        => $anime,
            'matched_rule_id' => $rule,
            'ep_no'           => $epNo,
            'message'         => mb_substr($r['msg'], 0, 480),
            'added_at'        => $r['ok'] ? date('Y-m-d H:i:s') : null,
        ], '`id` = :id', ['id' => $itemId]);

        return ['ok' => $r['ok'], 'msg' => $r['msg'], 'hash' => $r['hash']];
    }

    // ==================================================================
    //  护栏核心：所有批量加种都经过这里
    // ==================================================================

    /**
     * 对一条 rss_item 跑四道护栏并视情况加种。
     *
     * @param array $it       rss_item 行（需含 id / title / magnet）
     * @param array $m        匹配结果（anime_id / rule_id / ep_no）
     * @param array $opt      guardOptions() 的结果
     * @param array $stats    统计（引用累加）
     * @param bool  $catReady qB 类目是否已确保（引用，跨条目复用）
     * @param int   $added    本小时已加种数（引用，加种成功时自增）
     *
     * @return string added | blocked | failed | limit
     */
    private function guardAndAdd(array $it, array $m, array $opt, array &$stats, bool &$catReady, int &$added): string
    {
        $id       = (int) $it['id'];
        $title    = (string) $it['title'];
        $animeId  = (int) ($m['anime_id'] ?? 0);
        $ruleId   = isset($m['rule_id']) && $m['rule_id'] !== null ? (int) $m['rule_id'] : null;
        $epNo     = $m['ep_no'] ?? null;

        Database::update('rss_item', [
            'anime_id'        => $animeId > 0 ? $animeId : null,
            'matched_rule_id' => $ruleId,
            'ep_no'           => $epNo,
        ], '`id` = :rid', ['rid' => $id]);

        if ($ruleId !== null) {
            MatchEngine::bumpHit($ruleId);
        }

        // ---- 护栏 1：分辨率下限 ----
        $res = TitleParser::resolution($title);
        if ($opt['min_res'] > 0 && $res !== null && TitleParser::resWeight($res) < $opt['min_res']) {
            $stats['blocked']++;
            $stats['notes'][] = "跳过 {$res}：低于下限";
            Database::update('rss_item', [
                'status'  => 3,
                'message' => "分辨率 {$res} 低于下限",
            ], '`id` = :rid', ['rid' => $id]);
            return 'blocked';
        }

        // ---- 护栏 2：同番同集去重 ----
        // 既要看「已入库」(episode)，也要看「已加种/已入库的条目」(rss_item)——
        // 否则同一集被不同字幕组各发一版时会重复加种。
        if ($opt['dedupe'] && $animeId > 0 && $epNo !== null && $epNo > 0) {
            $dup = (int) Database::val(
                'SELECT
                   (SELECT COUNT(*) FROM `episode`  WHERE `anime_id` = :aid AND `ep_no` = :ep)
                 + (SELECT COUNT(*) FROM `rss_item` WHERE `anime_id` = :aid AND `ep_no` = :ep
                                                      AND `status` IN (1, 2) AND `id` <> :sid)
                 AS `n`',
                ['aid' => $animeId, 'ep' => $epNo, 'sid' => $id]
            );
            if ($dup > 0) {
                $stats['blocked']++;
                $stats['notes'][] = "跳过第 {$epNo} 集：已有下载/已入库";
                Database::update('rss_item', [
                    'status'  => 3,
                    'message' => "第 {$epNo} 集已有下载或已入库，跳过重复",
                ], '`id` = :rid', ['rid' => $id]);
                return 'blocked';
            }
        }

        // ---- 护栏 3：每小时加种上限 ----
        if ($opt['hourly_limit'] > 0 && $added >= $opt['hourly_limit']) {
            $stats['blocked']++;
            $stats['notes'][] = "本小时已达加种上限 {$opt['hourly_limit']}，剩余排队";
            return 'limit';
        }

        // ---- 磁力校验 ----
        $magnet = (string) ($it['magnet'] ?? '');
        if ($magnet === '' || stripos($magnet, 'magnet:') !== 0) {
            $stats['failed']++;
            Database::update('rss_item', [
                'status'  => 4,
                'message' => '缺少磁力链接',
            ], '`id` = :rid', ['rid' => $id]);
            return 'failed';
        }

        // ---- 加种 ----
        if (!$catReady) {
            $this->qb->ensureCategory($opt['category']);
            $catReady = true;
        }

        $r = $this->qb->add($magnet, $opt['save_path'], $opt['category']);

        if ($r['ok']) {
            $dup = !empty($r['duplicate']);
            $stats['added']++;
            $added++;
            Database::update('rss_item', [
                'status'       => 1,
                'torrent_hash' => $r['hash'],
                'infohash'     => $r['hash'],
                'message'      => $dup ? '已在 qBittorrent 中，跳过重复加种' : '已加入下载队列',
                'added_at'     => date('Y-m-d H:i:s'),
            ], '`id` = :rid', ['rid' => $id]);
            usleep(120000); // 别把 qB 打爆
            return 'added';
        }

        $stats['failed']++;
        $stats['notes'][] = '加种失败: ' . $r['msg'];
        Database::update('rss_item', [
            'status'  => 4,
            'message' => mb_substr($r['msg'], 0, 480),
        ], '`id` = :rid', ['rid' => $id]);
        return 'failed';
    }
}
