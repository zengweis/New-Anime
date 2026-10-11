<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Database;
use App\Core\FileLock;
use App\Core\Request;
use App\Core\Response;
use App\Core\S3;
use App\Core\Setting;
use App\Service\AutoDownloader;
use App\Service\FetchService;
use App\Service\MatchEngine;
use App\Service\MediaProbe;
use App\Service\Qbittorrent;
use App\Service\RemuxService;
use App\Service\S3TaskLog;
use App\Service\S3Uploader;
use App\Service\SubtitleService;
use App\Service\SyncService;
use App\Service\TitleParser;

/**
 * 后台：番剧 / 规则 / RSS / 条目 / 下载 / 同步。
 */
final class AdminController
{
    // ==================================================================
    //  总览
    // ==================================================================

    /** GET /api/admin/overview */
    public function overview(): void
    {
        $qb = new Qbittorrent();

        $qbInfo = null;
        try {
            $qbInfo = [
                'online'   => true,
                'version'  => $qb->version(),
                'transfer' => $qb->transfer(),
                'count'    => count($qb->torrents((string) Setting::get('qb_category', 'anime'))),
            ];
        } catch (\Throwable $e) {
            $qbInfo = ['online' => false, 'error' => $e->getMessage()];
        }

        // 占用空间分成两个口径：
        //   - 本机：真扫盘得到（download_root 里的视频 + remux 产物 + 字幕缓存），
        //           不含已上传后删除的集（那些本地确实不占空间）。
        //   - 远程：episode 里已上传对象的 s3_size 之和（R2/S3 侧实际占用）。
        // 历史遗留：老字段 `size` 是 SUM(file_size)，把远程文件也算进本地，
        // 会造成「本地占 10GB」的错觉（实际文件早已删除），故保留但不推荐前端使用。
        $storage = $this->storageInfo();

        Response::ok([
            'stats' => [
                'anime'       => (int) Database::val('SELECT COUNT(*) FROM `anime`'),
                'episode'     => (int) Database::val('SELECT COUNT(*) FROM `episode`'),
                'size'        => $storage['total'], // 兼容旧前端：改为二者之和
                'users'       => (int) Database::val('SELECT COUNT(*) FROM `user`'),
                'users_pend'  => (int) Database::val('SELECT COUNT(*) FROM `user` WHERE `status` IN (0,2)'),
                'items_todo'  => (int) Database::val('SELECT COUNT(*) FROM `rss_item` WHERE `status` = 0'),
                'items_unmatched' => (int) Database::val('SELECT COUNT(*) FROM `rss_item` WHERE `anime_id` IS NULL'),
                'rules'       => (int) Database::val('SELECT COUNT(*) FROM `match_rule` WHERE `enabled` = 1'),
                'mail_todo'   => (int) Database::val('SELECT COUNT(*) FROM `mail_queue` WHERE `status` = 0'),
            ],
            'storage' => $storage,
            'qb'    => $qbInfo,
            'jobs'  => Database::all(
                'SELECT `job`,`ok`,`new_count`,`message`,`cost_ms`,`created_at`
                 FROM `job_log` WHERE `job` <> \'login_fail\'
                 ORDER BY `id` DESC LIMIT 10'
            ),
            'disk'  => $this->diskInfo(),
        ]);
    }

    // ==================================================================
    //  番剧
    // ==================================================================

    /** GET /api/admin/animes */
    public function animes(): void
    {
        $page = max(1, Request::int('page', 1));
        $per  = min(100, max(1, Request::int('per_page', 30)));
        $off  = ($page - 1) * $per;
        $kw   = Request::str('keyword');

        $where = '1=1';
        $bind  = [];
        if ($kw !== '') {
            $where .= ' AND (`title` LIKE :kw OR `title_cn` LIKE :kw OR `alias` LIKE :kw)';
            $bind['kw'] = '%' . $kw . '%';
        }

        $total = (int) Database::val("SELECT COUNT(*) FROM `anime` WHERE {$where}", $bind);
        $rows  = Database::all(
            "SELECT a.*,
                    (SELECT COUNT(*) FROM `match_rule` r WHERE r.`anime_id` = a.`id`) AS `rule_count`,
                    (SELECT COUNT(*) FROM `episode` e WHERE e.`anime_id` = a.`id`) AS `real_eps`
             FROM `anime` a WHERE {$where}
             ORDER BY a.`id` DESC LIMIT {$per} OFFSET {$off}",
            $bind
        );

        foreach ($rows as &$r) {
            $r['id']         = (int) $r['id'];
            $r['rule_count'] = (int) $r['rule_count'];
            $r['real_eps']   = (int) $r['real_eps'];
        }
        unset($r);

        Response::ok(['list' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per]);
    }

    /** POST /api/admin/animes */
    public function createAnime(): void
    {
        $title = Request::str('title');
        if ($title === '') {
            Response::fail('标题不能为空');
        }
        $year = Request::int('year', 0) ?: null;

        if (Database::val('SELECT 1 FROM `anime` WHERE `title` = :t AND `year` <=> :y', ['t' => $title, 'y' => $year]) !== null) {
            Response::fail('同名同年的番剧已存在');
        }

        $id = Database::insert('anime', [
            'title'    => $title,
            'title_cn' => Request::str('title_cn') ?: null,
            'alias'    => Request::str('alias') ?: null,
            'cover'    => Request::str('cover') ?: null,
            'year'     => $year,
            'season'   => Request::int('season', 0) ?: null,
            'summary'  => Request::str('summary') ?: null,
            'status'   => Request::int('status', 0),
        ]);

        MatchEngine::flush();
        Response::ok(['id' => $id], '番剧已创建');
    }

    /** PUT /api/admin/animes/{id} */
    public function updateAnime(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        if (Database::val('SELECT 1 FROM `anime` WHERE `id` = :id', ['id' => $id]) === null) {
            Response::notFound('番剧不存在');
        }

        $data = [];
        foreach (['title', 'title_cn', 'alias', 'cover', 'summary'] as $k) {
            if (Request::input($k) !== null) {
                $data[$k] = Request::str($k) ?: null;
            }
        }
        // 只有显式传了才更新，否则局部更新会把 year/season 清空
        foreach (['year', 'season'] as $k) {
            if (Request::input($k) !== null) {
                $data[$k] = Request::int($k, 0) ?: null;
            }
        }
        if (Request::input('status') !== null) {
            $data['status'] = Request::int('status', 0);
        }

        if ($data) {
            Database::update('anime', $data, '`id` = :id', ['id' => $id]);
        }
        Response::ok(null, '已保存');
    }

    /** DELETE /api/admin/animes/{id} */
    public function deleteAnime(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        // episode 由外键 CASCADE 一并清理
        Database::run('DELETE FROM `anime` WHERE `id` = :id', ['id' => $id]);
        Database::run('DELETE FROM `match_rule` WHERE `anime_id` = :id', ['id' => $id]);
        Database::run('DELETE FROM `user_favorite` WHERE `anime_id` = :id', ['id' => $id]);
        MatchEngine::flush();
        Response::ok(null, '已删除');
    }

    /** GET /api/admin/animes/{id}/episodes —— 该番剧已入库的集数 */
    public function animeEpisodes(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        if (Database::val('SELECT 1 FROM `anime` WHERE `id` = :id', ['id' => $id]) === null) {
            Response::notFound('番剧不存在');
        }

        $rows = Database::all(
            'SELECT `id`,`ep_no`,`title`,`file_path`,`file_size`,`resolution`,`vcodec`,`acodec`,`subgroup`,
                    `remux_path`,`views`,`created_at`,
                    `s3_key`,`s3_size`,`s3_status`,`s3_err`,`s3_purged_at`,`s3_remote_only`,`s3_attempts`
             FROM `episode` WHERE `anime_id` = :id
             ORDER BY `ep_no` ASC, `id` ASC',
            ['id' => $id]
        );

        foreach ($rows as &$r) {
            $r['id']        = (int) $r['id'];
            $r['ep_no']     = (float) $r['ep_no'];
            $r['file_size'] = (int) $r['file_size'];
            $r['views']     = (int) $r['views'];

            $remux = (string) ($r['remux_path'] ?? '');
            $orig  = (string) ($r['file_path'] ?? '');
            $s3k   = (string) ($r['s3_key'] ?? '');

            // 本地是否还有可播文件（remux 产物或原始文件任一存在即可）
            $r['local'] = ($remux !== '' && is_file($remux)) || ($orig !== '' && is_file($orig));

            // 「已优化」要按**实际会被发送出去的文件**判断，而不是本地文件在不在：
            // 上传后本地被删，发出去的是远端对象（remux 产物 MP4），
            // 若还按本地判断就会把一个已优化的集显示成「原始」。
            if ($r['local']) {
                $r['remuxed'] = $remux !== '' && is_file($remux);
            } elseif ($s3k !== '') {
                $r['remuxed'] = MediaProbe::isNativeContainer(basename($s3k))
                    && ($orig === '' || !MediaProbe::isNativeContainer(basename($orig)));
            } else {
                $r['remuxed'] = false;
            }

            $r['s3_key']         = ($r['s3_key'] ?? '') !== '' ? (string) $r['s3_key'] : null;
            $r['s3_size']        = (int) $r['s3_size'];
            $r['s3_status']      = (int) $r['s3_status'];
            $r['s3_attempts']    = (int) $r['s3_attempts'];
            $r['s3_remote_only'] = (int) $r['s3_remote_only'] === 1;
            $r['s3_purged']      = ($r['s3_purged_at'] ?? null) !== null;

            // 存储位置：remote=只有远程 / both=本地+远程 / local=只有本地
            $r['storage'] = $r['local'] ? ($r['s3_key'] !== null ? 'both' : 'local') : 'remote';

            unset($r['remux_path'], $r['file_path'], $r['s3_purged_at']);
        }
        unset($r);

        Response::ok([
            'list'         => $rows,
            's3_enabled'   => S3::enabled(),
            'delete_local' => Setting::get('s3_delete_local', '1') === '1',
        ]);
    }

    /** POST /api/admin/animes/{id}/backfill —— 手动补录磁力链接 */
    public function backfillAnime(array $args): void
    {
        $animeId = (int) ($args['id'] ?? 0);
        if (Database::val('SELECT 1 FROM `anime` WHERE `id` = :id', ['id' => $animeId]) === null) {
            Response::notFound('番剧不存在');
        }

        $magnet = trim(Request::str('magnet'));
        if ($magnet === '' || stripos($magnet, 'magnet:') !== 0) {
            Response::fail('请输入合法的磁力链接（magnet:?xt=urn:btih:...）');
        }

        // 标题：优先取用户填写的，否则从磁力 dn= 参数解
        $title = trim(Request::str('title'));
        if ($title === '') {
            $title = self::magnetTitle($magnet);
        }
        if ($title === '') {
            Response::fail('无法从磁力链接识别标题，请手动填写标题');
        }

        $hash = TitleParser::infohashFromMagnet($magnet);
        if ($hash === null) {
            Response::fail('磁力链接里没有有效的 btih infohash');
        }

        $parsed     = TitleParser::parse($title);
        $epNo       = $parsed['ep_no'];
        $subgroup   = $parsed['subgroup'];
        $resolution = $parsed['resolution'];
        $lang       = $parsed['subtitle_lang'];

        // 同一 infohash 已存在则复用条目，只修正归属
        $item = Database::one(
            'SELECT `id` FROM `rss_item` WHERE `infohash` = :h OR `torrent_hash` = :h ORDER BY `id` DESC LIMIT 1',
            ['h' => $hash]
        );

        if ($item === null) {
            $itemId = (int) Database::insert('rss_item', [
                'guid'          => 'backfill:' . $hash,
                'title'         => mb_substr($title, 0, 500),
                'magnet'        => $magnet,
                'infohash'      => $hash,
                'subgroup'      => $subgroup !== null ? mb_substr($subgroup, 0, 100) : null,
                'ep_no'         => $epNo,
                'resolution'    => $resolution,
                'subtitle_lang' => $lang,
                'anime_id'      => $animeId,
            ]);
        } else {
            $itemId = (int) $item['id'];
            Database::update('rss_item', ['anime_id' => $animeId], '`id` = :id', ['id' => $itemId]);
        }

        // 显式加种，不套护栏
        $category = (string) Setting::get('qb_category', 'anime');
        $qb       = new Qbittorrent();
        $qb->ensureCategory($category);
        $r = $qb->add($magnet, (string) Setting::get('download_root', '/data/downloads/anime'), $category);

        Database::update('rss_item', [
            'status'       => $r['ok'] ? 1 : 4,
            'torrent_hash' => $r['hash'],
            'infohash'     => $r['hash'] !== null ? $r['hash'] : $hash,
            'message'      => mb_substr($r['msg'], 0, 480),
            'added_at'     => $r['ok'] ? date('Y-m-d H:i:s') : null,
        ], '`id` = :id', ['id' => $itemId]);

        Response::ok([
            'item_id'   => $itemId,
            'hash'      => $hash,
            'title'     => $title,
            'ep_no'     => $epNo,
            'ok'        => $r['ok'],
            'duplicate' => $r['duplicate'] ?? false,
            'msg'       => $r['msg'],
        ], $r['ok'] ? '已提交下载' : ('补录失败：' . $r['msg']));
    }

    /** 从磁力链接的 dn= 参数解出标题 */
    private static function magnetTitle(string $magnet): string
    {
        if (preg_match('/[?&]dn=([^&]+)/i', $magnet, $m)) {
            return trim((string) rawurldecode($m[1]));
        }
        return '';
    }

    // ==================================================================
    //  许愿墙
    // ==================================================================

    /** GET /api/admin/wishes —— 许愿数据列表 */
    public function wishes(): void
    {
        $rows = Database::all(
            'SELECT w.`id`, w.`user_id`, w.`anime_name`, w.`note`, w.`status`, w.`result_note`, w.`created_at`, w.`updated_at`,
                    u.`username`, u.`nickname`
             FROM `wish` w JOIN `user` u ON u.`id` = w.`user_id`
             ORDER BY w.`status` ASC, w.`created_at` DESC
             LIMIT 500'
        );

        foreach ($rows as &$r) {
            $r['id']        = (int) $r['id'];
            $r['user_id']   = (int) $r['user_id'];
            $r['status']    = (int) $r['status'];
            $r['status_cn'] = match ((int) $r['status']) {
                1 => '已实现',
                2 => '实现中',
                3 => '暂无计划',
                default => '许愿中',
            };
        }
        unset($r);

        Response::ok(['list' => $rows, 'daily_limit' => (int) Setting::get('wish_daily_limit', '5')]);
    }

    /** POST /api/admin/wishes/{id}/resolve —— 标记许愿结果（0许愿中 1已实现 2实现中 3暂无计划） */
    public function resolveWish(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        if (Database::val('SELECT 1 FROM `wish` WHERE `id` = :id', ['id' => $id]) === null) {
            Response::notFound('许愿不存在');
        }

        $status = Request::int('status', -1);
        if (!in_array($status, [0, 1, 2, 3], true)) {
            Response::fail('状态只能是：许愿中 / 已实现 / 实现中 / 暂无计划');
        }

        $note = Request::str('result_note');
        Database::update('wish', [
            'status'      => $status,
            'result_note' => $note !== '' ? $note : null,
        ], '`id` = :id', ['id' => $id]);

        Response::ok(null, '状态已更新');
    }

    /** DELETE /api/admin/wishes/{id} —— 删除愿望 */
    public function deleteWish(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        Database::run('DELETE FROM `wish` WHERE `id` = :id', ['id' => $id]);
        Response::ok(null, '已删除');
    }

    /** POST /api/admin/wishes/limit —— 设置每账户每日许愿上限 */
    public function setWishLimit(): void
    {
        $limit = Request::int('limit', 5);
        if ($limit < 1 || $limit > 100) {
            Response::fail('每日上限需在 1-100 之间');
        }
        Setting::set('wish_daily_limit', (string) $limit);
        Response::ok(['daily_limit' => $limit], '每日许愿上限已更新');
    }

    // ==================================================================
    //  匹配规则
    // ==================================================================

    /** GET /api/admin/rules */
    public function rules(): void
    {
        $animeId = Request::int('anime_id', 0);
        $sql = 'SELECT r.*, a.`title` AS `anime_title`, a.`title_cn` AS `anime_title_cn`
                FROM `match_rule` r JOIN `anime` a ON a.`id` = r.`anime_id`';
        $bind = [];
        if ($animeId > 0) {
            $sql .= ' WHERE r.`anime_id` = :aid';
            $bind['aid'] = $animeId;
        }
        $sql .= ' ORDER BY r.`priority` DESC, r.`id` DESC LIMIT 500';

        Response::ok(['list' => Database::all($sql, $bind)]);
    }

    /** POST /api/admin/rules */
    public function createRule(): void
    {
        $animeId = Request::int('anime_id');
        $pattern = Request::str('pattern');
        $flags   = Request::str('flags') ?: 'iu';
        $epCap   = Request::int('ep_capture', 1);
        $autoDl  = Request::bool('auto_download', true);

        if ($animeId <= 0 || Database::val('SELECT 1 FROM `anime` WHERE `id` = :id', ['id' => $animeId]) === null) {
            Response::fail('请选择所属番剧');
        }
        if (($err = MatchEngine::validate($pattern, $flags)) !== null) {
            Response::fail($err);
        }

        $cap = max(0, min(9, $epCap));
        $id = Database::insert('match_rule', [
            'anime_id'      => $animeId,
            'pattern'       => $pattern,
            'flags'         => $flags,
            'ep_capture'    => $cap,
            'priority'      => Request::int('priority', 10),
            'enabled'       => Request::bool('enabled', true) ? 1 : 0,
            'auto_download' => $autoDl ? 1 : 0,
        ]);

        MatchEngine::flush();

        // 新规则的典型场景是「给已抓到的条目补归类」，所以立刻回填一次
        $back = $this->backfill($id, $animeId, $pattern, $flags, $cap);

        // 归类完立刻开下，免得条目堆在「待处理」里还得手动点
        $dl = $autoDl ? (new AutoDownloader())->downloadForAnime($animeId) : null;

        Response::ok([
            'id'         => $id,
            'backfilled' => $back,
            'auto_download' => $autoDl,
            'download'   => $dl,
        ], self::ruleSaveMsg($back, $autoDl, $dl));
    }

    /**
     * 规则保存后的提示语。
     *
     * @param int       $back   回填条目数
     * @param bool|null $autoDl null=本次未涉及下载（如只改优先级），不提示
     * @param array|null $dl    加种统计
     */
    private static function ruleSaveMsg(int $back, ?bool $autoDl, ?array $dl): string
    {
        $parts = [];
        if ($back > 0) {
            $parts[] = "归类了 {$back} 条历史条目";
        }
        if ($dl !== null) {
            $parts[] = self::downloadMsg($dl);
        } elseif ($autoDl === false) {
            $parts[] = '未开启自动下载，可稍后手动下载';
        }
        return '规则已保存' . ($parts ? '，' . implode('，', $parts) : '');
    }

    /** 加种统计 → 人话 */
    private static function downloadMsg(array $dl): string
    {
        $parts = [];
        if (($n = (int) ($dl['added'] ?? 0)) > 0) {
            $parts[] = "已加种 {$n} 条";
        }
        if (($n = (int) ($dl['blocked'] ?? 0)) > 0) {
            $parts[] = "跳过 {$n} 条";
        }
        if (($n = (int) ($dl['failed'] ?? 0)) > 0) {
            $parts[] = "失败 {$n} 条";
        }
        return $parts ? implode('，', $parts) : '没有待下载的条目';
    }

    /** POST /api/admin/rules/{id}/download —— 手动下载该规则命中的待处理条目 */
    public function downloadRule(array $args): void
    {
        $ruleId = (int) ($args['id'] ?? 0);
        $animeId = AutoDownloader::ruleAnimeId($ruleId);
        if ($animeId === null) {
            Response::notFound('规则不存在');
        }

        $dl = (new AutoDownloader())->downloadForAnime($animeId);

        Response::ok($dl, self::downloadMsg($dl));
    }

    /** PUT /api/admin/rules/{id} */
    public function updateRule(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        if (Database::val('SELECT 1 FROM `match_rule` WHERE `id` = :id', ['id' => $id]) === null) {
            Response::notFound('规则不存在');
        }

        $patternChanged = false;
        $data = [];
        if (Request::input('pattern') !== null) {
            $pattern = Request::str('pattern');
            $flags   = Request::str('flags') ?: 'iu';
            if (($err = MatchEngine::validate($pattern, $flags)) !== null) {
                Response::fail($err);
            }
            $data['pattern'] = $pattern;
            $data['flags']   = $flags;
            $patternChanged  = true;
        }
        foreach (['ep_capture', 'priority'] as $k) {
            if (Request::input($k) !== null) {
                $data[$k] = Request::int($k, 0);
            }
        }
        // 布尔字段单独走 bool()：Request::int(true) 会因 is_numeric 判定失败而变成 0
        foreach (['enabled', 'auto_download'] as $k) {
            if (Request::input($k) !== null) {
                $data[$k] = Request::bool($k) ? 1 : 0;
            }
        }

        if ($data) {
            Database::update('match_rule', $data, '`id` = :id', ['id' => $id]);
        }
        MatchEngine::flush();

        // 改了匹配条件就重跑一次：回填历史条目 + （若开启自动下载）立刻开下。
        // 只改优先级/开关时不触发，避免误加种。
        $back = 0;
        $dl   = null;
        $autoDl = null; // null = 本次不改匹配条件，不提下载
        if ($patternChanged) {
            $rule = Database::one(
                'SELECT `anime_id`, `pattern`, `flags`, `ep_capture`, `auto_download` FROM `match_rule` WHERE `id` = :id',
                ['id' => $id]
            );
            if ($rule !== null) {
                $animeId = (int) $rule['anime_id'];
                $back = $this->backfill($id, $animeId, (string) $rule['pattern'], (string) $rule['flags'], (int) $rule['ep_capture']);
                $autoDl = (int) $rule['auto_download'] === 1;
                if ($autoDl) {
                    $dl = (new AutoDownloader())->downloadForAnime($animeId);
                }
            }
        }

        Response::ok([
            'id'         => $id,
            'backfilled' => $back,
            'download'   => $dl,
        ], self::ruleSaveMsg($back, $autoDl, $dl));
    }

    /** DELETE /api/admin/rules/{id} */
    public function deleteRule(array $args): void
    {
        Database::run('DELETE FROM `match_rule` WHERE `id` = :id', ['id' => (int) ($args['id'] ?? 0)]);
        MatchEngine::flush();
        Response::ok(null, '已删除');
    }

    /**
     * POST /api/admin/rules/preview —— 可视化筛选台的核心接口。
     * 拿最近 N 条 RSS 条目，实时显示正则命中情况。
     */
    public function previewRule(): void
    {
        $pattern = (string) Request::input('pattern', '');
        $flags   = Request::str('flags') ?: 'iu';
        $limit   = Request::int('limit', 200);
        $epCap   = Request::int('ep_capture', 1);

        try {
            $r = MatchEngine::preview($pattern, $flags, $limit, $epCap);
        } catch (\Throwable $e) {
            Response::fail($e->getMessage());
        }

        // 同时给出「如果保存，会命中哪些番剧」的提示
        $r['hint'] = $r['hit'] === 0
            ? '当前正则没有命中任何条目，试试放宽条件'
            : sprintf('命中 %d / %d 条', $r['hit'], $r['total']);

        Response::ok($r);
    }

    /** POST /api/admin/rules/suggest —— 依据真实标题生成正则草稿 */
    public function suggestRule(): void
    {
        $title = Request::str('title');
        if ($title === '') {
            Response::fail('请提供标题');
        }
        $s = MatchEngine::suggest($title);
        $s['preview'] = MatchEngine::preview($s['pattern'], 'iu', 200, 1);
        $s['preview']['items'] = array_slice($s['preview']['items'], 0, 50);
        Response::ok($s);
    }

    // ==================================================================
    //  RSS 源
    // ==================================================================

    /** GET /api/admin/feeds */
    public function feeds(): void
    {
        Response::ok(['list' => Database::all('SELECT * FROM `rss_feed` ORDER BY `id` ASC')]);
    }

    /** POST /api/admin/feeds */
    public function createFeed(): void
    {
        $name = Request::str('name');
        $url  = Request::str('url');
        if ($name === '' || $url === '') {
            Response::fail('名称和地址不能为空');
        }
        if (!preg_match('#^https?://#i', $url)) {
            Response::fail('RSS 地址必须是 http(s) 链接');
        }

        $id = Database::insert('rss_feed', [
            'name'       => $name,
            'url'        => $url,
            'enabled'    => Request::bool('enabled', true) ? 1 : 0,
            'auto_down'  => Request::bool('auto_down', true) ? 1 : 0,
            'filter_re'  => Request::str('filter_re') ?: null,
            'exclude_re' => Request::str('exclude_re') ?: null,
            'min_res'    => Request::str('min_res') ?: '1080',
        ]);
        Response::ok(['id' => $id], '订阅源已添加');
    }

    /** PUT /api/admin/feeds/{id} */
    public function updateFeed(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        if (Database::val('SELECT 1 FROM `rss_feed` WHERE `id` = :id', ['id' => $id]) === null) {
            Response::notFound('订阅源不存在');
        }
        $data = [];
        foreach (['name', 'url', 'filter_re', 'exclude_re', 'min_res'] as $k) {
            if (Request::input($k) !== null) {
                $data[$k] = Request::str($k);
            }
        }
        foreach (['enabled', 'auto_down'] as $k) {
            if (Request::input($k) !== null) {
                $data[$k] = Request::bool($k) ? 1 : 0;
            }
        }
        if ($data) {
            Database::update('rss_feed', $data, '`id` = :id', ['id' => $id]);
        }
        Response::ok(null, '已保存');
    }

    /** DELETE /api/admin/feeds/{id} */
    public function deleteFeed(array $args): void
    {
        Database::run('DELETE FROM `rss_feed` WHERE `id` = :id', ['id' => (int) ($args['id'] ?? 0)]);
        Response::ok(null, '已删除');
    }

    /** POST /api/admin/feeds/fetch —— 手动抓取（可指定 id） */
    public function fetch(): void
    {
        $feedId = Request::int('feed_id', 0) ?: null;
        $t0 = microtime(true);

        try {
            $r = (new FetchService())->fetchAndDownload($feedId, 300);
        } catch (\Throwable $e) {
            Response::fail('抓取失败：' . $e->getMessage());
        }

        $cost = (int) ((microtime(true) - $t0) * 1000);
        FetchService::logJob('fetch_manual', true, (int) $r['fetch']['new'], '手动抓取', $cost);

        Response::ok($r, sprintf(
            '抓取 %d 条（新增 %d），自动加种 %d 个',
            $r['fetch']['fetched'],
            $r['fetch']['new'],
            $r['auto']['added'] ?? 0
        ));
    }

    // ==================================================================
    //  RSS 条目
    // ==================================================================

    /** GET /api/admin/items */
    public function items(): void
    {
        $page   = max(1, Request::int('page', 1));
        $per    = min(100, max(1, Request::int('per_page', 50)));
        $off    = ($page - 1) * $per;
        $status = Request::int('status', -1);
        $unmatched = Request::bool('unmatched');
        $kw     = Request::str('keyword');

        $where = ['1=1'];
        $bind  = [];
        if ($status >= 0) {
            $where[] = 'i.`status` = :st';
            $bind['st'] = $status;
        }
        if ($unmatched) {
            $where[] = 'i.`anime_id` IS NULL';
        }
        if ($kw !== '') {
            $where[] = 'i.`title` LIKE :kw';
            $bind['kw'] = '%' . $kw . '%';
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) Database::val("SELECT COUNT(*) FROM `rss_item` i WHERE {$whereSql}", $bind);
        $rows  = Database::all(
            "SELECT i.`id`, i.`title`, i.`subgroup`, i.`ep_no`, i.`resolution`, i.`subtitle_lang`,
                    i.`status`, i.`message`, i.`anime_id`, i.`torrent_hash`, i.`pub_date`, i.`created_at`,
                    i.`magnet` IS NOT NULL AS `has_magnet`,
                    i.`matched_rule_id`,
                    a.`title_cn` AS `anime_title_cn`, a.`title` AS `anime_title`
             FROM `rss_item` i
             LEFT JOIN `anime` a ON a.`id` = i.`anime_id`
             WHERE {$whereSql}
             ORDER BY i.`pub_date` DESC, i.`id` DESC
             LIMIT {$per} OFFSET {$off}",
            $bind
        );

        foreach ($rows as &$r) {
            $r['id']         = (int) $r['id'];
            $r['status']     = (int) $r['status'];
            $r['status_cn']  = self::itemStatusCn($r['status']);
            $r['has_magnet'] = (bool) $r['has_magnet'];
        }
        unset($r);

        Response::ok(['list' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per]);
    }

    /** POST /api/admin/items/{id}/download */
    public function downloadItem(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        $r  = (new AutoDownloader())->downloadOne($id);
        $r['ok'] ? Response::ok($r, $r['msg']) : Response::fail($r['msg']);
    }

    /** POST /api/admin/items/{id}/ignore */
    public function ignoreItem(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        Database::update('rss_item', ['status' => 3, 'message' => '手动忽略'], '`id` = :id', ['id' => $id]);
        Response::ok(null, '已忽略');
    }

    /** POST /api/admin/items/{id}/assign —— 手动指派番剧 */
    public function assignItem(array $args): void
    {
        $id      = (int) ($args['id'] ?? 0);
        $animeId = Request::int('anime_id');
        if (Database::val('SELECT 1 FROM `anime` WHERE `id` = :id', ['id' => $animeId]) === null) {
            Response::fail('番剧不存在');
        }
        $epNo = Request::input('ep_no');
        $data = ['anime_id' => $animeId, 'status' => 0];
        if ($epNo !== null && $epNo !== '') {
            $data['ep_no'] = (float) $epNo;
        }
        Database::update('rss_item', $data, '`id` = :id', ['id' => $id]);
        Response::ok(null, '已指派');
    }

    /** POST /api/admin/items/quick-create —— 从条目一键建番 + 建规则 */
    public function quickCreate(): void
    {
        $title    = Request::str('title');
        $titleCn  = Request::str('title_cn');
        $year     = Request::int('year', 0) ?: null;
        $season   = Request::int('season', 0) ?: null;
        $cover    = Request::str('cover') ?: null;
        $pattern  = Request::str('pattern');
        $epCap    = Request::int('ep_capture', 1);
        $itemId   = Request::int('item_id', 0);

        if ($title === '' && $titleCn === '') {
            Response::fail('请填写番剧名称');
        }
        if ($pattern !== '' && ($err = MatchEngine::validate($pattern, 'iu')) !== null) {
            Response::fail($err);
        }

        $main = $titleCn !== '' ? $titleCn : $title;

        $exist = Database::one(
            'SELECT `id` FROM `anime` WHERE `title` = :t AND `year` <=> :y LIMIT 1',
            ['t' => $main, 'y' => $year]
        );

        $animeId = $exist !== null
            ? (int) $exist['id']
            : Database::insert('anime', [
                'title'    => $main,
                'title_cn' => $titleCn ?: null,
                'alias'    => $title !== '' && $title !== $main ? $title : null,
                'cover'    => $cover,
                'year'     => $year,
                'season'   => $season,
                'status'   => 0,
            ]);

        $ruleId = null;
        $autoDl = Request::bool('auto_download', true);
        if ($pattern !== '') {
            $ruleId = Database::insert('match_rule', [
                'anime_id'      => $animeId,
                'pattern'       => $pattern,
                'flags'         => 'iu',
                'ep_capture'    => max(0, min(9, $epCap)),
                'priority'      => 10,
                'enabled'       => 1,
                'auto_download' => $autoDl ? 1 : 0,
            ]);
        }

        if ($itemId > 0) {
            Database::update('rss_item', [
                'anime_id'        => $animeId,
                'matched_rule_id' => $ruleId,
                'status'          => 0,
            ], '`id` = :id', ['id' => $itemId]);
        }

        MatchEngine::flush();

        // 立刻回填已抓到的历史条目，并（若开启）直接开下
        $back = 0;
        $dl   = null;
        if ($ruleId !== null) {
            $back = $this->backfill($ruleId, $animeId, $pattern, 'iu', max(0, min(9, $epCap)));
            if ($autoDl) {
                $dl = (new AutoDownloader())->downloadForAnime($animeId);
            }
        }

        Response::ok([
            'anime_id' => $animeId,
            'rule_id'  => $ruleId,
            'backfilled' => $back,
            'auto_download' => $autoDl,
            'download' => $dl,
        ], $back > 0 ? "已创建番剧，并归类了 {$back} 条历史条目" : '已创建番剧');
    }

    /** 用新规则回填历史条目（同时登记 matched_rule_id，便于后续按规则追责/下载） */
    private function backfill(int $ruleId, int $animeId, string $pattern, string $flags, int $epCap): int
    {
        $re = MatchEngine::compile($pattern, $flags);
        if ($re === null) {
            return 0;
        }
        $rows = Database::all(
            'SELECT `id`,`title` FROM `rss_item` WHERE `anime_id` IS NULL ORDER BY `pub_date` DESC LIMIT 1000'
        );
        $n = 0;
        foreach ($rows as $r) {
            if (!preg_match($re, (string) $r['title'], $m)) {
                continue;
            }
            $ep = ($epCap > 0 && isset($m[$epCap]) && is_numeric($m[$epCap]))
                ? (float) $m[$epCap]
                : TitleParser::epNo((string) $r['title']);
            Database::update('rss_item', [
                'anime_id'        => $animeId,
                'matched_rule_id' => $ruleId,
                'ep_no'           => $ep,
            ], '`id` = :id', ['id' => (int) $r['id']]);
            $n++;
        }
        return $n;
    }

    /** POST /api/admin/items/batch-download */
    public function batchDownload(): void
    {
        $ids = Request::input('ids', []);
        if (!is_array($ids) || !$ids) {
            Response::fail('请选择条目');
        }
        $dl = new AutoDownloader();
        $ok = 0;
        $fail = 0;
        foreach (array_slice($ids, 0, 50) as $id) {
            $r = $dl->downloadOne((int) $id);
            $r['ok'] ? $ok++ : $fail++;
        }
        Response::ok(['ok' => $ok, 'fail' => $fail], "成功 {$ok} 个，失败 {$fail} 个");
    }

    // ==================================================================
    //  下载 / 同步
    // ==================================================================

    /** GET /api/admin/torrents */
    public function torrents(): void
    {
        try {
            $qb   = new Qbittorrent();
            $list = $qb->torrents((string) Setting::get('qb_category', 'anime'));
            $info = $qb->transfer();
            $ver  = $qb->version();
        } catch (\Throwable $e) {
            Response::fail('无法连接 qBittorrent：' . $e->getMessage());
        }

        $out = [];
        foreach ($list as $t) {
            $out[] = [
                'hash'       => (string) ($t['hash'] ?? ''),
                'name'       => (string) ($t['name'] ?? ''),
                'size'       => (int) ($t['size'] ?? 0),
                'progress'   => round((float) ($t['progress'] ?? 0) * 100, 1),
                'state'      => (string) ($t['state'] ?? ''),
                'state_cn'   => Qbittorrent::stateLabel((string) ($t['state'] ?? '')),
                'done'       => Qbittorrent::isFinished($t),
                'dlspeed'    => (int) ($t['dlspeed'] ?? 0),
                'upspeed'    => (int) ($t['upspeed'] ?? 0),
                'eta'        => (int) ($t['eta'] ?? 0),
                'save_path'  => (string) ($t['save_path'] ?? ''),
                'added_on'   => (int) ($t['added_on'] ?? 0),
                'num_seeds'  => (int) ($t['num_seeds'] ?? 0),
            ];
        }
        usort($out, static fn($a, $b) => $b['added_on'] <=> $a['added_on']);

        Response::ok([
            'list'     => $out,
            'version'  => $ver,
            'transfer' => $info,
            'paused'   => false,
        ]);
    }

    /** POST /api/admin/torrents/action */
    public function torrentAction(): void
    {
        $action = Request::str('action');
        $hashes = Request::input('hashes', []);
        if (!is_array($hashes) || !$hashes) {
            Response::fail('请选择任务');
        }
        $hashes = array_slice(array_map('strval', $hashes), 0, 200);

        $qb = new Qbittorrent();
        $ok = match ($action) {
            'pause'  => $qb->pause($hashes),
            'resume' => $qb->resume($hashes),
            'delete' => $qb->delete($hashes, Request::bool('delete_files')),
            default  => false,
        };

        $ok ? Response::ok(null, '操作成功') : Response::fail('操作失败，请检查 qBittorrent');
    }

    /** POST /api/admin/torrents/pause-finished —— 把已完成任务全部暂停（省上行流量） */
    public function pauseFinished(): void
    {
        try {
            $r = SyncService::pauseFinished();
        } catch (\Throwable $e) {
            Response::fail('暂停失败：' . $e->getMessage());
        }

        if ($r['paused'] === 0 && $r['failed'] === 0) {
            Response::ok($r, '没有正在做种的已完成任务（已暂停 ' . $r['skipped'] . ' 个）');
        }
        if ($r['failed'] > 0) {
            Response::fail(sprintf('暂停失败 %d 个，请检查 qBittorrent', $r['failed']));
        }
        Response::ok($r, sprintf('已暂停 %d 个任务的做种', $r['paused']));
    }

    /** POST /api/admin/sync —— 手动触发下载完成同步 */
    public function sync(): void
    {
        $t0 = microtime(true);
        $r  = (new SyncService())->run();
        FetchService::logJob('sync_manual', empty($r['errors']), (int) $r['episodes'], '手动同步', (int) ((microtime(true) - $t0) * 1000));

        Response::ok($r, sprintf(
            '扫描 %d 个任务，新增 %d 集',
            $r['torrents'],
            $r['episodes']
        ));
    }

    /** POST /api/admin/sync/refresh —— 重算番剧集数 */
    public function refreshAll(): void
    {
        $n = (new SyncService())->refreshAll();
        Response::ok(['count' => $n], "已重算 {$n} 部番剧");
    }

    // ==================================================================
    //  日志
    // ==================================================================

    /** GET /api/admin/logs */
    public function logs(): void
    {
        $rows = Database::all(
            'SELECT * FROM `job_log` WHERE `job` <> \'login_fail\' ORDER BY `id` DESC LIMIT 100'
        );
        Response::ok(['list' => $rows]);
    }

    // ==================================================================
    //  对象存储（S3 / R2）
    // ==================================================================

    /** GET /api/admin/s3/objects —— 拉取远程文件列表 */
    public function s3Objects(): void
    {
        $up = self::uploader();
        if ($up === null) {
            Response::fail(S3::configHint() ?: '对象存储不可用');
        }

        $prefix = Request::str('prefix');
        $token  = Request::str('cursor');
        $limit  = min(500, max(1, Request::int('limit', 100)));

        $r = $up->remoteList($prefix, $token !== '' ? $token : null, $limit);
        if (!$r['ok']) {
            Response::fail('拉取远程列表失败：' . $r['error']);
        }

        Response::ok([
            'list'      => $r['objects'],
            'cursor'    => $r['next'],
            'truncated' => $r['truncated'],
            'prefix'    => $prefix !== '' ? trim($prefix, '/') : S3::prefix(),
        ]);
    }

    /** POST /api/admin/s3/upload/{id} —— 手动上传单集 */
    public function s3Upload(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        if (Database::val('SELECT 1 FROM `episode` WHERE `id` = :id', ['id' => $id]) === null) {
            Response::notFound('剧集不存在');
        }
        $up = self::uploader();
        if ($up === null) {
            Response::fail(S3::configHint() ?: '对象存储不可用');
        }

        // 手动上传视为明确意图，重试计数清零并强制重传
        Database::run('UPDATE `episode` SET `s3_attempts` = 0 WHERE `id` = :id', ['id' => $id]);

        $r = $up->upload($id, Request::bool('force', false));
        if ($r['busy']) {
            Response::fail('已有上传任务在进行，请稍后再试');
        }
        if (!$r['ok']) {
            Response::fail('上传失败：' . ($r['error'] ?: $r['reason']));
        }

        Response::ok($r, match ($r['reason']) {
            'already_uploaded', 'already_remote' => '该集已在远端，无需重复上传',
            'waiting_remux' => '等待 remux 完成后会自动上传',
            default => '上传完成' . ($r['purged']
                ? '，本地文件已删除'
                : (($r['purge_error'] ?? null) !== null
                    ? '，但本地文件未删除：' . $r['purge_error']
                    : '')),
        });
    }

    /** POST /api/admin/s3/upload-batch —— 上传待传队列 */
    public function s3UploadBatch(): void
    {
        $up = self::uploader();
        if ($up === null) {
            Response::fail(S3::configHint() ?: '对象存储不可用');
        }

        $limit = max(1, min(20, Request::int('limit', (int) Setting::get('s3_upload_limit', 2))));
        $pend  = S3Uploader::pending($limit);
        if (!$pend) {
            Response::ok(['done' => 0, 'failed' => 0, 'waiting' => 0], '没有待上传的剧集');
        }

        $done = 0;
        $fail = 0;
        $wait = 0;
        $noPurge = 0;
        $errs = [];
        foreach ($pend as $row) {
            $r = $up->upload((int) $row['id']);
            if ($r['busy']) {
                break;
            }
            if ($r['ok']) {
                $done++;
                if (!$r['purged'] && ($r['purge_error'] ?? null) !== null) {
                    $noPurge++;
                    $errs[] = '#' . $row['id'] . ' ' . $r['purge_error'];
                }
            } elseif ($r['reason'] === 'waiting_remux') {
                $wait++;
            } else {
                $fail++;
                if ($r['error']) {
                    $errs[] = '#' . $row['id'] . ' ' . $r['error'];
                }
            }
        }

        $msg = sprintf('上传完成 %d，失败 %d，等待 remux %d', $done, $fail, $wait);
        if ($noPurge > 0) {
            $msg .= sprintf('；有 %d 集本地文件未删除（磁盘未释放）', $noPurge);
        }
        Response::ok(
            ['done' => $done, 'failed' => $fail, 'waiting' => $wait, 'no_purge' => $noPurge, 'errors' => array_slice($errs, 0, 5)],
            $msg
        );
    }

    /** POST /api/admin/s3/unlink/{id} —— 解除某集的远端关联 */
    public function s3Unlink(array $args): void
    {
        $id = (int) ($args['id'] ?? 0);
        $row = Database::one(
            'SELECT `id`,`file_path`,`remux_path` FROM `episode` WHERE `id` = :id',
            ['id' => $id]
        );
        if ($row === null) {
            Response::notFound('剧集不存在');
        }

        // 解绑后这一集就得靠本地文件播。本地没有可播文件时必须拦住，
        // 否则用户点一下「解绑」这一集就永远播不了了。
        // 注意要同时判断两种情况：已上传后删了本地（file_path 还在但文件没了）、
        // 以及补档引用来的仅远程集（file_path 本来就是空串）。
        $hasLocal = false;
        foreach ([$row['remux_path'], $row['file_path']] as $p) {
            $p = (string) $p;
            if ($p !== '' && is_file($p)) {
                $hasLocal = true;
                break;
            }
        }
        if (!$hasLocal) {
            Response::fail('该集本地没有可播放的文件，解除关联后会无法播放；请先把文件取回本地');
        }

        S3Uploader::unlink($id);
        Response::ok(null, '已解除远端关联（远端对象未删除，本地文件保留）');
    }

    /** POST /api/admin/animes/{id}/backfill-remote —— 用远程对象补档 */
    public function backfillRemote(array $args): void
    {
        $animeId = (int) ($args['id'] ?? 0);
        if (Database::val('SELECT 1 FROM `anime` WHERE `id` = :id', ['id' => $animeId]) === null) {
            Response::notFound('番剧不存在');
        }
        $up = self::uploader();
        if ($up === null) {
            Response::fail(S3::configHint() ?: '对象存储不可用');
        }

        $keys = Request::input('keys');
        if (!is_array($keys)) {
            $one  = Request::str('key');
            $keys = $one !== '' ? [$one] : [];
        }
        $keys = array_values(array_filter(array_map(
            static fn($k): string => trim((string) $k),
            $keys
        ), static fn(string $k): bool => $k !== ''));
        if (!$keys) {
            Response::fail('请选择要补档的远程文件');
        }

        $ok = [];
        $failed = [];
        foreach ($keys as $k) {
            $r = $up->importRemote($animeId, $k, [
                'title'      => count($keys) === 1 ? Request::str('title') : '',
                'ep_no'      => count($keys) === 1 ? Request::str('ep_no') : '',
                'resolution' => count($keys) === 1 ? Request::str('resolution') : '',
            ]);
            if ($r['ok']) {
                $ok[] = ['key' => $k, 'episode_id' => $r['episode_id'], 'ep_no' => $r['ep_no']];
            } else {
                $failed[] = ['key' => $k, 'error' => $r['error']];
            }
        }

        $msg = sprintf('补档成功 %d 条', count($ok));
        if ($failed) {
            $msg .= '，失败 ' . count($failed) . ' 条';
        }
        Response::ok(['ok' => $ok, 'failed' => $failed], $msg);
    }

    /** GET /api/admin/animes/{id}/remote-candidates —— 该番剧可能对应的远程文件（按目录猜） */
    public function remoteCandidates(array $args): void
    {
        $animeId = (int) ($args['id'] ?? 0);
        $up = self::uploader();
        if ($up === null) {
            Response::fail(S3::configHint() ?: '对象存储不可用');
        }

        $prefix = Request::str('prefix');
        $r = $up->remoteList($prefix, null, min(200, max(1, Request::int('limit', 100))));
        if (!$r['ok']) {
            Response::fail('拉取远程列表失败：' . $r['error']);
        }

        Response::ok(['list' => $r['objects'], 'cursor' => $r['next'], 'truncated' => $r['truncated']]);
    }

    /** 构造上传器；未启用或配置不全返回 null */
    private static function uploader(): ?S3Uploader
    {
        if (!S3::enabled()) {
            return null;
        }
        try {
            return new S3Uploader();
        } catch (\Throwable) {
            return null;
        }
    }

    // ==================================================================
    //  上传任务监控
    // ==================================================================

    /**
     * GET /api/admin/s3/tasks
     *
     * 后台「上传任务」页的数据源。一次返回：
     *   - stats   汇总（进行中/排队/今日成功/今日失败/今日字节/远端占用/本地剩余/锁状态）
     *   - queue   待上传队列里「这一轮能传的」与「被门槛挡住的」分别是多少
     *   - list    任务历史（分页）
     */
    public function s3Tasks(): void
    {
        $status = Request::int('status', -1);
        $page   = max(1, Request::int('page', 1));
        $per    = min(100, max(5, Request::int('per_page', 30)));

        $data = S3TaskLog::list(
            in_array($status, [0, 1, 2], true) ? $status : -1,
            $page,
            $per
        );

        $stats = S3TaskLog::stats();
        $stats['usage']  = S3Uploader::stats();

        // 队列口径：能传的 = pending()（含全部门槛），被挡的 = queued - 能传
        $ready = 0;
        if (S3::enabled()) {
            try {
                $ready = count(S3Uploader::pending(200));
            } catch (\Throwable) {
                $ready = 0;
            }
        }
        $stats['ready']   = $ready;
        $stats['blocked'] = max(0, (int) ($stats['usage']['queued'] ?? 0) - $ready);

        // 锁文件可用性：锁被别的用户占住会让任务**静默不跑**，所以放在监控页上
        $locks = [];
        foreach ([
            '字幕抽取' => \App\Service\SubtitleService::root() . '/.extract.lock',
            'remux'    => RemuxService::root() . '/.remux.lock',
            '上传'     => RemuxService::root() . '/.s3upload.lock',
        ] as $name => $path) {
            $d = FileLock::diagnose($path);
            $locks[] = ['name' => $name, 'path' => $d['path'], 'ok' => $d['ok'], 'note' => $d['note']];
        }
        $stats['locks'] = $locks;

        $stats['enabled']     = S3::enabled();
        $stats['config_hint'] = S3::configHint();
        $stats['auto_upload'] = Setting::get('s3_auto_upload', '1') === '1';
        $stats['delete_local'] = Setting::get('s3_delete_local', '1') === '1';
        $stats['daemon']      = self::daemonState();

        Response::ok($data + ['stats' => $stats]);
    }

    /** POST /api/admin/s3/tasks/retry/{id} —— 重试某条失败任务（重新排队该集） */
    public function s3TaskRetry(array $args): void
    {
        $taskId = (int) ($args['id'] ?? 0);
        $task = Database::one('SELECT * FROM `s3_task` WHERE `id` = :id LIMIT 1', ['id' => $taskId]);
        if ($task === null) {
            Response::notFound('任务不存在');
        }
        $epId = (int) ($task['episode_id'] ?? 0);
        if ($epId <= 0 || Database::val('SELECT 1 FROM `episode` WHERE `id` = :i', ['i' => $epId]) === null) {
            Response::fail('该任务对应的剧集已不存在，无法重试');
        }

        // 重置剧集的上传状态与尝试次数，让它重新进入队列
        Database::run(
            'UPDATE `episode`
                SET `s3_status` = 0, `s3_attempts` = 0, `s3_err` = NULL
              WHERE `id` = :id',
            ['id' => $epId]
        );

        // 立即尝试一次（手动触发，记 manual）
        $up = self::uploader();
        if ($up === null) {
            Response::ok(['queued' => true], '已重新排队（对象存储当前不可用，将在恢复后自动上传）');
        }
        $r = $up->upload($epId, true, false, 'manual', (string) (Auth::user()['username'] ?? ''));
        if ($r['busy']) {
            Response::ok(['queued' => true, 'busy' => true], '已重新排队：已有上传在进行，本集将在下一轮处理');
        }
        if (!$r['ok']) {
            Response::fail('重试失败：' . ($r['error'] ?: $r['reason']));
        }
        Response::ok($r, '重试成功' . ($r['purged'] ? '，本地文件已删除' : ''));
    }

    /** POST /api/admin/s3/tasks/clear —— 清理已完成的历史 */
    public function s3TaskClear(): void
    {
        $days = Request::int('days', 7);
        $n    = S3TaskLog::clearFinished($days);
        Response::ok(['count' => $n], "已清理 {$n} 条 {$days} 天前的成功记录");
    }

    /** daemon 是否在跑，以及最近的日志时间（监控页要能看出"任务为什么不动"） */
    private static function daemonState(): array
    {
        $log  = APP_ROOT . '/storage/logs/daemon.log';
        $mtime = is_file($log) ? (int) filemtime($log) : 0;
        return [
            'log_fresh_seconds' => $mtime > 0 ? max(0, time() - $mtime) : null,
        ];
    }

    // ------------------------------------------------------------------

    private static function itemStatusCn(int $s): string
    {
        return match ($s) {
            0 => '待处理',
            1 => '已加种',
            2 => '已入库',
            3 => '已忽略',
            4 => '失败',
            default => '未知',
        };
    }

    private function diskInfo(): array
    {
        $root = (string) Setting::get('download_root', '/data/downloads/anime');
        $free = @disk_free_space($root);
        $total = @disk_total_space($root);
        return [
            'path'      => $root,
            'free'      => $free !== false ? (int) $free : 0,
            'total'     => $total !== false ? (int) $total : 0,
            // 应用只需要「读 + 进入」：qBittorrent 负责写，nginx/www-data 负责读着发流
            'readable'  => is_readable($root) && is_executable($root),
            'writable'  => is_writable($root),
        ];
    }

    // ==================================================================
    //  存储占用（本机 / 远程）
    // ==================================================================

    /**
     * 存储占用拆成两个口径，互不重叠：
     *
     *   本机（local）——真扫盘，不含数据库里的历史字节数：
     *       video  下载根目录里真实存在的视频（remux 产物 + 未删的原片）
     *       subs   subtitle_root 下的字幕缓存（VTT / 位图 WebP）
     *       other  下载根目录内的其余文件（.part / .lock / 未完成碎片）
     *
     *   远程（remote）——已上传对象的字节数之和：
     *       bytes     SUM(s3_size) WHERE s3_status = 1
     *       objects   条数
     *
     * 之所以真扫盘而不是 SUM(file_size)：上传到 S3 后本地文件会被删除，
     * 但 file_path / file_size 仍留着（清空会让 RemuxService 反复排队），
     * 于是 SUM(file_size) 会把「早就删掉、只存在云上」的字节算进本机占用。
     *
     * 扫盘按 mtime 缓存 60 秒（RSS 列表页一次请求要对几万文件 stat 太亏），
     * 缓存文件放在系统临时目录，不依赖 storage 可写。
     */
    private function storageInfo(): array
    {
        $local = $this->localUsage();
        $remote = $this->remoteUsage();

        return [
            'local' => [
                'bytes'  => $local['bytes'],
                'video'  => $local['video'],
                'subs'   => $local['subs'],
                'other'  => $local['other'],
                'count'  => $local['count'],
                'roots'  => $local['roots'],
                'cached' => $local['cached'],
            ],
            'remote' => [
                'bytes'    => $remote['bytes'],
                'objects'  => $remote['objects'],
                'enabled'  => S3::enabled(),
                'prefix'   => S3::prefix(),
                'public_base' => S3::publicBase(),
            ],
            'total' => $local['bytes'] + $remote['bytes'],
        ];
    }

    /** 远程对象占用：只统计上传成功且 s3_size 有效的行 */
    private function remoteUsage(): array
    {
        $row = Database::one(
            'SELECT COUNT(*) AS `objects`, COALESCE(SUM(`s3_size`),0) AS `bytes`
             FROM `episode` WHERE `s3_status` = 1 AND `s3_key` IS NOT NULL AND `s3_key` <> \'\''
        ) ?? [];

        return [
            'objects' => (int) ($row['objects'] ?? 0),
            'bytes'   => (int) ($row['bytes'] ?? 0),
        ];
    }

    /**
     * 本机占用：真扫两个根目录。结果缓存 60 秒。
     *
     * 跳过 0 字节文件与锁文件（qB 在跑时 .part 会不断变化，统计它意义不大）。
     */
    private function localUsage(): array
    {
        $downloadRoot = (string) Setting::get('download_root', '/data/downloads/anime');
        $subsRoot     = SubtitleService::root();

        $cacheFile = rtrim(sys_get_temp_dir(), '/\\') . '/anime-storage-usage.json';
        $cached = false;
        $data = null;

        if (is_file($cacheFile) && (time() - (int) @filemtime($cacheFile)) < 60) {
            $raw = @file_get_contents($cacheFile);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                // 缓存里记着当时的根目录，目录改了就不能复用
                if (is_array($decoded)
                    && ($decoded['download_root'] ?? '') === $downloadRoot
                    && ($decoded['subs_root'] ?? '') === $subsRoot
                ) {
                    $data = $decoded;
                    $cached = true;
                }
            }
        }

        if ($data === null) {
            $video = $this->dirUsage($downloadRoot, ['video']);
            $subs  = $this->dirUsage($subsRoot, ['subs']);

            $data = [
                'download_root' => $downloadRoot,
                'subs_root'     => $subsRoot,
                'bytes'   => $video['bytes'] + $subs['bytes'],
                'video'   => $video['bytes'],
                'subs'    => $subs['bytes'],
                'other'   => $video['other'],
                'count'   => $video['count'] + $subs['count'],
                'roots'   => [
                    'download' => $downloadRoot,
                    'subtitle' => $subsRoot,
                ],
                'at' => time(),
            ];

            // 扫盘结果落缓存；目录不可写也不会让接口 500
            @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX);
        }

        $data['cached'] = $cached;
        return $data;
    }

    /**
     * 递归统计一个目录：返回总字节数与文件数。
     *
     * `$bucket` 决定把体积记到哪个分类（video / subs），另一类记为 other，
     * 用于把「remux 产物残留的 .tmp 碎片」这类非成片文件单独拎出来。
     *
     * @param array<int,string> $videoBucket 归入 video 的分类名（仅对视频根目录有意义）
     * @return array{bytes:int,count:int,other:int}
     */
    private function dirUsage(string $dir, array $videoBucket = []): array
    {
        $bytes = 0;
        $count = 0;
        $other = 0;

        if ($dir === '' || !is_dir($dir)) {
            return ['bytes' => 0, 'count' => 0, 'other' => 0];
        }

        // 下载根目录下已知的非视频子目录（按名字跳过统计到 video 里）
        $isVideoRoot = in_array('video', $videoBucket, true);

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            try {
                if (!$file->isFile() || $file->isLink()) {
                    continue;
                }
            } catch (\Throwable $e) {
                continue;
            }

            $name = $file->getFilename();
            // 锁文件、qB 的半成品不算占用（前者恒为 0 字节，后者还在长）
            if (str_starts_with($name, '.') && str_ends_with($name, '.lock')) {
                continue;
            }

            $size = @$file->getSize();
            if ($size === false || $size <= 0) {
                continue;
            }

            $bytes += $size;
            $count++;

            if ($isVideoRoot) {
                // remux 产物都在 {download_root}/remux/ 下，属于成片
                $path = str_replace('\\', '/', $file->getPathname());
                $isRemux = str_contains($path, '/remux/');
                $ext = strtolower($file->getExtension());
                if ($ext === 'mp4' || $ext === 'mkv' || $ext === 'avi' || $ext === 'ts' || $isRemux) {
                    // 归 video
                } else {
                    $other += $size;
                }
            }
        }

        return ['bytes' => $bytes, 'count' => $count, 'other' => $other];
    }
}
