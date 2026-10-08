<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Setting;

final class MeController
{
    /** GET /api/me/favorites */
    public function favorites(): void
    {
        $u = Auth::requireUser();
        $uid = (int) $u['id'];

        $rows = Database::all(
            'SELECT a.`id`, a.`title`, a.`title_cn`, a.`cover`, a.`year`, a.`season`,
                    a.`status`, a.`ep_count`, a.`last_ep`, a.`updated_at`,
                    COALESCE(h.`max_ep`, 0) AS `watched_ep`, f.`created_at` AS `fav_at`
             FROM `user_favorite` f
             JOIN `anime` a ON a.`id` = f.`anime_id`
             LEFT JOIN (
                 -- 看到第几集要靠 episode.ep_no，user_history 只存 episode_id
                 SELECT h2.`anime_id`, MAX(e2.`ep_no`) AS `max_ep`
                 FROM `user_history` h2
                 JOIN `episode` e2 ON e2.`id` = h2.`episode_id`
                 WHERE h2.`user_id` = :u2
                 GROUP BY h2.`anime_id`
             ) h ON h.`anime_id` = a.`id`
             WHERE f.`user_id` = :u
             ORDER BY a.`updated_at` DESC, f.`created_at` DESC
             LIMIT 200',
            ['u' => $uid, 'u2' => $uid]
        );

        foreach ($rows as &$r) {
            $r['id']         = (int) $r['id'];
            $r['ep_count']   = (int) $r['ep_count'];
            $r['watched_ep'] = (float) $r['watched_ep'];
            $r['has_new']    = $r['last_ep'] !== null
                && (float) $r['last_ep'] > (float) $r['watched_ep'];
        }
        unset($r);

        Response::ok(['list' => $rows, 'total' => count($rows)]);
    }

    /** POST /api/me/favorites/{id} */
    public function addFavorite(array $args): void
    {
        $u = Auth::requireUser();
        $animeId = (int) ($args['id'] ?? 0);
        if (Database::val('SELECT 1 FROM `anime` WHERE `id` = :id', ['id' => $animeId]) === null) {
            Response::notFound('番剧不存在');
        }
        Database::run(
            'INSERT IGNORE INTO `user_favorite` (`user_id`,`anime_id`) VALUES (:u,:a)',
            ['u' => (int) $u['id'], 'a' => $animeId]
        );
        Response::ok(['is_fav' => true], '已加入追番');
    }

    /** DELETE /api/me/favorites/{id} */
    public function removeFavorite(array $args): void
    {
        $u = Auth::requireUser();
        $animeId = (int) ($args['id'] ?? 0);
        Database::run(
            'DELETE FROM `user_favorite` WHERE `user_id` = :u AND `anime_id` = :a',
            ['u' => (int) $u['id'], 'a' => $animeId]
        );
        Response::ok(['is_fav' => false], '已取消追番');
    }

    /** GET /api/me/history */
    public function history(): void
    {
        $u = Auth::requireUser();
        $page = max(1, Request::int('page', 1));
        $per  = min(60, max(1, Request::int('per_page', 30)));
        $off  = ($page - 1) * $per;
        $uid  = (int) $u['id'];

        $total = (int) Database::val('SELECT COUNT(*) FROM `user_history` WHERE `user_id` = :u', ['u' => $uid]);

        $rows = Database::all(
            "SELECT h.`episode_id`, h.`position_sec`, h.`duration_sec`, h.`finished`, h.`updated_at`,
                    e.`ep_no`, e.`title` AS `ep_title`,
                    a.`id` AS `anime_id`, a.`title`, a.`title_cn`, a.`cover`
             FROM `user_history` h
             JOIN `episode` e ON e.`id` = h.`episode_id`
             JOIN `anime` a ON a.`id` = h.`anime_id`
             WHERE h.`user_id` = :u
             ORDER BY h.`updated_at` DESC
             LIMIT {$per} OFFSET {$off}",
            ['u' => $uid]
        );

        foreach ($rows as &$r) {
            $r['episode_id'] = (int) $r['episode_id'];
            $r['anime_id']   = (int) $r['anime_id'];
            $r['ep_no']      = (float) $r['ep_no'];
            $r['percent']    = (int) $r['duration_sec'] > 0
                ? min(100, (int) round((int) $r['position_sec'] / (int) $r['duration_sec'] * 100))
                : 0;
        }
        unset($r);

        Response::ok(['list' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per]);
    }

    /** POST /api/me/progress —— 播放器定时上报 */
    public function reportProgress(): void
    {
        $u = Auth::requireUser();
        $epId = Request::int('episode_id');
        $pos  = max(0, Request::int('position_sec'));
        $dur  = max(0, Request::int('duration_sec'));

        $ep = Database::one('SELECT `id`,`anime_id` FROM `episode` WHERE `id` = :id', ['id' => $epId]);
        if ($ep === null) {
            Response::fail('剧集不存在');
        }

        $finished = ($dur > 0 && $pos >= $dur * 0.92) ? 1 : 0;

        Database::run(
            'INSERT INTO `user_history`
               (`user_id`,`episode_id`,`anime_id`,`position_sec`,`duration_sec`,`finished`)
             VALUES (:u,:e,:a,:p,:d,:f)
             ON DUPLICATE KEY UPDATE
               `position_sec` = VALUES(`position_sec`),
               `duration_sec` = VALUES(`duration_sec`),
               `finished`     = VALUES(`finished`)',
            [
                'u' => (int) $u['id'],
                'e' => $epId,
                'a' => (int) $ep['anime_id'],
                'p' => $pos,
                'd' => $dur,
                'f' => $finished,
            ]
        );

        Response::ok(null, '已记录');
    }

    /** GET /api/me/continue —— 继续观看 */
    public function continueWatching(): void
    {
        $u = Auth::requireUser();
        $uid = (int) $u['id'];

        $rows = Database::all(
            'SELECT h.`episode_id`, h.`position_sec`, h.`duration_sec`, h.`updated_at`,
                    e.`ep_no`, a.`id` AS `anime_id`, a.`title`, a.`title_cn`, a.`cover`,
                    a.`ep_count`, a.`last_ep`
             FROM `user_history` h
             JOIN `episode` e ON e.`id` = h.`episode_id`
             JOIN `anime` a ON a.`id` = h.`anime_id`
             WHERE h.`user_id` = :u AND h.`finished` = 0
             ORDER BY h.`updated_at` DESC LIMIT 12',
            ['u' => $uid]
        );

        foreach ($rows as &$r) {
            $r['episode_id'] = (int) $r['episode_id'];
            $r['anime_id']   = (int) $r['anime_id'];
            $r['ep_no']      = (float) $r['ep_no'];
        }
        unset($r);

        Response::ok(['list' => $rows]);
    }

    /** GET /api/me/stats */
    public function stats(): void
    {
        $u = Auth::requireUser();
        $uid = (int) $u['id'];
        Response::ok([
            'favorites' => (int) Database::val('SELECT COUNT(*) FROM `user_favorite` WHERE `user_id` = :u', ['u' => $uid]),
            'watched'   => (int) Database::val('SELECT COUNT(*) FROM `user_history` WHERE `user_id` = :u', ['u' => $uid]),
            'finished'  => (int) Database::val('SELECT COUNT(*) FROM `user_history` WHERE `user_id` = :u AND `finished` = 1', ['u' => $uid]),
        ]);
    }

    // ------------------------------------------------------------------
    //  许愿墙
    // ------------------------------------------------------------------

    /** GET /api/wishes —— 许愿墙列表（公开可看，带 token 时标 is_mine） */
    public function wishes(): void
    {
        $uid = (int) (Auth::id() ?? 0);

        $rows = Database::all(
            'SELECT w.`id`, w.`user_id`, w.`anime_name`, w.`note`, w.`status`, w.`result_note`, w.`created_at`,
                    u.`username`, u.`nickname`
             FROM `wish` w JOIN `user` u ON u.`id` = w.`user_id`
             ORDER BY w.`created_at` DESC
             LIMIT 300'
        );

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'id'          => (int) $r['id'],
                'anime_name'  => (string) $r['anime_name'],
                'note'        => $r['note'],
                'status'      => (int) $r['status'],
                'status_cn'   => self::wishStatusCn((int) $r['status']),
                'result_note' => $r['result_note'],
                'created_at'  => $r['created_at'],
                'is_mine'     => $uid > 0 && (int) $r['user_id'] === $uid,
                'user'        => [
                    'username' => (string) $r['username'],
                    'nickname' => (string) ($r['nickname'] ?: $r['username']),
                ],
            ];
        }

        Response::ok(['list' => $list, 'total' => count($list)]);
    }

    /** POST /api/wishes —— 自己许愿 */
    public function createWish(): void
    {
        $u    = Auth::requireUser();
        $uid  = (int) $u['id'];
        $name = trim(Request::str('anime_name'));

        if ($name === '') {
            Response::fail('请填写想看的动漫名称');
        }
        if (mb_strlen($name) > 200) {
            Response::fail('动漫名称过长');
        }

        // 每日许愿上限
        $limit = max(1, (int) Setting::get('wish_daily_limit', '5'));
        $today = (int) Database::val(
            'SELECT COUNT(*) FROM `wish` WHERE `user_id` = :u AND `created_at` >= CURDATE()',
            ['u' => $uid]
        );
        if ($today >= $limit) {
            Response::fail("每天最多许愿 {$limit} 次，今天已经用完了");
        }

        // 同一用户不重复许愿同名动漫（未实现的）
        $dup = Database::val(
            'SELECT 1 FROM `wish` WHERE `user_id` = :u AND `anime_name` = :n AND `status` = 0',
            ['u' => $uid, 'n' => $name]
        );
        if ($dup !== null) {
            Response::fail('你已经许愿过这个动漫了');
        }

        $id = (int) Database::insert('wish', [
            'user_id'    => $uid,
            'anime_name' => $name,
            'note'       => Request::str('note') ?: null,
        ]);

        Response::ok(['id' => $id], '许愿成功');
    }

    private static function wishStatusCn(int $s): string
    {
        return match ($s) {
            1 => '已实现',
            2 => '实现中',
            3 => '暂无计划',
            default => '许愿中',
        };
    }
}
