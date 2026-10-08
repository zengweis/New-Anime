<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Database;

/**
 * 对象存储任务日志：写 `s3_task` 表，供后台「上传任务」页展示实时状态与历史。
 *
 * 为什么需要一张专门的表，而不是从 `episode` 的 s3_* 字段推算：
 *   - `episode` 只存「最终状态」。而上传是长任务（757MB 分片要几分钟），
 *     后台要看到的是**进行中的进度**；失败重试的次数、每次的耗时与原因也留不下来。
 *   - 剧集被删掉之后历史仍需保留，所以剧名/集数在这里存快照，不建外键。
 *
 * 进度写入策略：分片上传每传若干片才更新一次库。
 *   757MB / 8MB ≈ 95 片，若每片都写库就是 95 次 UPDATE —— 对一个 2 核小机器来说
 *   是没必要的负担。这里按「进度百分比变化 >= 2% 或跨过整十片」才写。
 */
final class S3TaskLog
{
    /** 进行中但超过这个秒数没动静，视为进程被中断 */
    public const STALE_SECONDS = 900;

    /** 上一次写入的进度百分比（进程内，用于节流） */
    private static array $lastPct = [];

    /**
     * 开一条任务记录，返回任务 id。任何异常都不该影响上传本身。
     */
    public static function start(int $episodeId, string $action, array $ctx = []): int
    {
        try {
            $animeTitle = null;
            $animeId    = null;
            if ($episodeId > 0) {
                $row = Database::one(
                    'SELECT e.`ep_no`, e.`title` AS `ep_title`, a.`id` AS `anime_id`,
                            COALESCE(NULLIF(a.`title_cn`, \'\'), a.`title`) AS `anime_title`
                     FROM `episode` e LEFT JOIN `anime` a ON a.`id` = e.`anime_id`
                     WHERE e.`id` = :id LIMIT 1',
                    ['id' => $episodeId]
                );
                if ($row !== null) {
                    $animeId    = $row['anime_id'] !== null ? (int) $row['anime_id'] : null;
                    $animeTitle = $row['anime_title'] !== null ? mb_substr((string) $row['anime_title'], 0, 200) : null;
                    $ctx['ep_no']   = (float) $row['ep_no'];
                    $ctx['ep_title'] = mb_substr((string) $row['ep_title'], 0, 500);
                }
            }

            return Database::insert('s3_task', [
                'episode_id'  => $episodeId > 0 ? $episodeId : null,
                'anime_id'    => $animeId,
                'anime_title' => $animeTitle,
                'ep_no'       => $ctx['ep_no'] ?? null,
                'ep_title'    => $ctx['ep_title'] ?? null,
                'action'      => mb_substr($action, 0, 24),
                'status'      => 0,
                'source'      => isset($ctx['source']) ? mb_substr((string) $ctx['source'], 0, 255) : null,
                's3_key'      => isset($ctx['s3_key']) ? mb_substr((string) $ctx['s3_key'], 0, 1000) : null,
                'bytes_total' => (int) ($ctx['bytes_total'] ?? 0),
                'bytes_done'  => 0,
                'trigger'     => ($ctx['trigger'] ?? 'auto') === 'manual' ? 'manual' : 'auto',
                'actor'       => isset($ctx['actor']) ? mb_substr((string) $ctx['actor'], 0, 64) : null,
            ]);
        } catch (\Throwable) {
            return 0;   // 日志失败不能影响上传
        }
    }

    /** 分片总数写入（初始化分片上传后拿到） */
    public static function plan(int $taskId, int $bytesTotal, int $partsTotal, bool $multipart, ?string $key = null): void
    {
        if ($taskId <= 0) {
            return;
        }
        try {
            $data = [
                'bytes_total' => $bytesTotal,
                'parts_total' => $partsTotal,
                'multipart'   => $multipart ? 1 : 0,
            ];
            if ($key !== null && $key !== '') {
                $data['s3_key'] = mb_substr($key, 0, 1000);
            }
            Database::update('s3_task', $data, '`id` = :id', ['id' => $taskId]);
        } catch (\Throwable) {
        }
    }

    /** 进度上报（内部会节流） */
    public static function progress(int $taskId, int $bytesDone, int $partsDone, int $partsTotal): void
    {
        if ($taskId <= 0) {
            return;
        }
        $pct = $partsTotal > 0 ? (int) floor($partsDone * 100 / $partsTotal) : 0;
        $last = self::$lastPct[$taskId] ?? -1;
        // 节流：百分比每变化 2% 才写一次，避免 95 次无谓 UPDATE
        if ($pct < 100 && $pct - $last < 2) {
            return;
        }
        self::$lastPct[$taskId] = $pct;
        try {
            Database::update('s3_task', [
                'bytes_done' => $bytesDone,
                'parts_done' => $partsDone,
            ], '`id` = :id', ['id' => $taskId]);
        } catch (\Throwable) {
        }
    }

    /** 结束任务 */
    public static function finish(int $taskId, bool $ok, array $extra = []): void
    {
        if ($taskId <= 0) {
            return;
        }
        unset(self::$lastPct[$taskId]);
        try {
            $data = [
                'status'      => $ok ? 1 : 2,
                'finished_at' => date('Y-m-d H:i:s'),
                'duration_ms' => (int) ($extra['duration_ms'] ?? 0),
                'error'       => isset($extra['error']) && $extra['error'] !== null
                    ? mb_substr((string) $extra['error'], 0, 500)
                    : null,
                'purged'      => !empty($extra['purged']) ? 1 : 0,
            ];
            if (isset($extra['bytes_done'])) {
                $data['bytes_done'] = (int) $extra['bytes_done'];
            }
            if (isset($extra['purge_error'])) {
                $data['purge_error'] = $extra['purge_error'] !== null
                    ? mb_substr((string) $extra['purge_error'], 0, 500)
                    : null;
            }
            if (isset($extra['key']) && (string) $extra['key'] !== '') {
                $data['s3_key'] = mb_substr((string) $extra['key'], 0, 1000);
            }
            Database::update('s3_task', $data, '`id` = :id', ['id' => $taskId]);
        } catch (\Throwable) {
        }
    }

    // ==================================================================
    //  查询
    // ==================================================================

    /**
     * 概况：进行中 / 排队 / 今日成功 / 今日失败 / 今日字节 / 全量累计
     */
    public static function stats(): array
    {
        $today = date('Y-m-d') . ' 00:00:00';
        $row = Database::one(
            'SELECT
                SUM(`status` = 0 AND `started_at` >= DATE_SUB(NOW(), INTERVAL ' . self::STALE_SECONDS . ' SECOND)) AS running,
                SUM(`status` = 0 AND `started_at` <  DATE_SUB(NOW(), INTERVAL ' . self::STALE_SECONDS . ' SECOND)) AS stale,
                SUM(`status` = 1 AND `started_at` >= :today) AS ok_today,
                SUM(`status` = 2 AND `started_at` >= :today) AS fail_today,
                SUM(`status` = 1 AND `started_at` >= :today AND `action` = \'upload\') AS uploaded_today,
                COALESCE(SUM(CASE WHEN `status` = 1 AND `started_at` >= :today2 AND `action` = \'upload\' THEN `bytes_total` END), 0) AS bytes_today,
                COALESCE(SUM(CASE WHEN `status` = 1 AND `action` = \'upload\' THEN `bytes_total` END), 0) AS bytes_all,
                COUNT(*) AS total_all
             FROM `s3_task`',
            ['today' => $today, 'today2' => $today]
        ) ?? [];

        $out = [];
        foreach ($row as $k => $v) {
            $out[$k] = (int) $v;
        }
        $out['stale_seconds'] = self::STALE_SECONDS;
        return $out;
    }

    /**
     * 任务列表
     *
     * @param int $status -1 全部 / 0 进行中 / 1 成功 / 2 失败（2 也含疑似中断）
     */
    public static function list(int $status = -1, int $page = 1, int $perPage = 30): array
    {
        $perPage = max(5, min(100, $perPage));
        $page    = max(1, $page);
        $off     = ($page - 1) * $perPage;

        $where = '1=1';
        $bind  = [];
        if (in_array($status, [0, 1, 2], true)) {
            $where = '`status` = :st';
            $bind['st'] = $status;
        }

        $total = (int) Database::val("SELECT COUNT(*) FROM `s3_task` WHERE {$where}", $bind);
        $rows  = Database::all(
            "SELECT `id`,`episode_id`,`anime_id`,`anime_title`,`ep_no`,`ep_title`,`action`,
                    `status`,`source`,`s3_key`,`bytes_total`,`bytes_done`,`parts_total`,`parts_done`,
                    `multipart`,`purged`,`purge_error`,`error`,`duration_ms`,`trigger`,`actor`,`started_at`,`finished_at`
             FROM `s3_task` WHERE {$where}
             ORDER BY `id` DESC LIMIT {$perPage} OFFSET {$off}",
            $bind
        );

        foreach ($rows as &$r) {
            $r['id']          = (int) $r['id'];
            $r['episode_id']  = $r['episode_id'] !== null ? (int) $r['episode_id'] : null;
            $r['anime_id']    = $r['anime_id'] !== null ? (int) $r['anime_id'] : null;
            $r['ep_no']       = $r['ep_no'] !== null ? (float) $r['ep_no'] : null;
            $r['status']      = (int) $r['status'];
            $r['bytes_total'] = (int) $r['bytes_total'];
            $r['bytes_done']  = (int) $r['bytes_done'];
            $r['parts_total'] = (int) $r['parts_total'];
            $r['parts_done']  = (int) $r['parts_done'];
            $r['multipart']   = (int) $r['multipart'] === 1;
            $r['purged']      = (int) $r['purged'] === 1;
            $r['duration_ms'] = (int) $r['duration_ms'];
            $r['percent']     = $r['bytes_total'] > 0
                ? min(100, (int) round($r['bytes_done'] * 100 / $r['bytes_total']))
                : ($r['status'] === 1 ? 100 : 0);
            // 进程被杀 → status 一直是 0，靠时间判定为「已中断」
            $r['stale'] = $r['status'] === 0
                && (time() - (int) strtotime((string) $r['started_at'])) > self::STALE_SECONDS;
            $r['elapsed_ms'] = $r['finished_at'] !== null
                ? $r['duration_ms']
                : max(0, (int) ((time() - (int) strtotime((string) $r['started_at'])) * 1000));
        }
        unset($r);

        return ['list' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** 清掉已完成的历史（保留进行中与失败） */
    public static function clearFinished(int $days = 7): int
    {
        $days = max(1, min(365, $days));
        return Database::run(
            'DELETE FROM `s3_task`
              WHERE `status` = 1 AND `started_at` < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)'
        )->rowCount();
    }
}
