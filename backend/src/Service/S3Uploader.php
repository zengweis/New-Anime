<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Database;
use App\Core\FileLock;
use App\Core\Log;
use App\Core\S3;
use App\Core\Setting;

/**
 * 把下载好的剧集上传到对象存储（S3/R2），校验通过后删除本地文件，
 * 从而在「远程可访问」的同时省下本地磁盘。
 *
 * 设计上的几条硬约束：
 *
 * 1. **先校验再删本地**。上传完必须 HEAD 回查远端大小与本地一致，才允许 unlink。
 *    少了这一步，一次静默的截断上传就等于永久丢片。
 *
 * 2. **等 remux 到终态再传**。remux 出来的是浏览器原生可播的 MP4，而原始 MKV 只有
 *    HEVC 才能在 Chrome 里播。传早了会把不可原生播放的版本送上去，之后还得重传。
 *    remux 失败（remux_err 非空）也算终态——这时候传原始文件是唯一选择。
 *
 * 3. **删本地后保留 file_path / remux_path 原值**（只写 s3_purged_at 时间戳）。
 *    若把它们清空，RemuxService::pending() 会把这个「已删源文件」的集重新排队，
 *    每轮都失败一次。保留字符串让 remux 侧自然跳过它。
 *
 * 4. **flock 串行**。上传占带宽和内存，同一时刻只跑一路；并发只会让两边都变慢，
 *    还可能把 R2 的分片上传配额打满。
 */
final class S3Uploader
{
    /** 超过这个次数不再自动重试，避免坏文件永久占据队列 */
    public const MAX_ATTEMPTS = 5;

    private S3 $s3;

    public function __construct(?S3 $s3 = null)
    {
        $s3 = $s3 ?? S3::fromSettings();
        if ($s3 === null) {
            throw new \RuntimeException('对象存储未配置：' . S3::configHint());
        }
        $this->s3 = $s3;
    }

    // ==================================================================
    //  待上传队列
    // ==================================================================

    /**
     * 待上传的剧集。
     *
     * 排序刻意让「从未尝试过」的排在「失败重试」之前（s3_status 0 < 2），
     * 否则一个反复失败的条目会把整个队列堵死。
     *
     * 三道前置门槛，都是为了防止「还没榨干本地文件就被删掉」：
     *
     *   1. **已探测**（`probe_at` 非空）。探测没跑之前 `sub_tracks` 还是默认值 0，
     *      跟「确实没有字幕」无法区分，字幕门槛会被绕过；而且删掉唯一副本之后就
     *      永远不知道这集是什么编码了。探测失败也会写 `probe_at`，所以不会卡死。
     *
     *   2. **remux 到终态**。传的是浏览器原生可播的 MP4；原始 MKV 除 HEVC 外在 Chrome
     *      里播不了，传早了还得重传。remux 失败（remux_err 非空）也算终态——这时
     *      传原始文件是唯一选择。
     *
     *   3. **字幕已抽取或确认无字幕**（`sub_tracks = 0` / `sub_extract_at` 非空）。
     *      字幕是从本地文件抽的，抽完缓存在本地；如果先删了本地文件，这一集的内嵌
     *      字幕就再也没有机会抽出来了。`sub_extract_at` 在尝试后就写入（无论成败），
     *      所以不会永久堵住队列。
     */
    public static function pending(int $limit = 2): array
    {
        $limit = max(1, min(50, $limit));

        $remuxGate = Setting::get('s3_require_remux', '1') === '1'
            ? "AND (`remux_path` IS NOT NULL AND `remux_path` <> '' OR `remux_err` IS NOT NULL)"
            : '';

        return Database::all(
            'SELECT `id`,`anime_id`,`ep_no`,`title`,`file_path`,`remux_path`,`remux_err`,
                    `vcodec`,`s3_key`,`s3_attempts`
             FROM `episode`
             WHERE `s3_status` IN (0, 2)
               AND `s3_attempts` < ' . self::MAX_ATTEMPTS . '
               AND `file_path` <> \'\'
               AND `probe_at` IS NOT NULL
               AND (`sub_tracks` = 0 OR `sub_extract_at` IS NOT NULL)
             ' . $remuxGate . '
             ORDER BY `s3_status` ASC, `id` ASC
             LIMIT ' . $limit
        );
    }

    /**
     * 队列规模统计里的「待上传」口径与 pending() 对齐的说明：
     * `stats()` 里的 queued 是纯 SQL 计数（`s3_status = 0 AND file_path <> ''`），
     * 不含门槛 —— 它是「还没处理的量」，而不是「这一轮能传的量」。
     * 后台展示时两者含义不同，别混用。
     */
    public static function queueBlockedHint(): string
    {
        return '待上传还包含尚未探测/remux/抽字幕的集，它们在门槛满足后会自动进入上传';
    }

    /** 队列规模统计（后台展示用） */
    public static function stats(): array
    {
        $row = Database::one(
            "SELECT
                COUNT(*)                                                       AS total,
                SUM(`s3_status` = 1)                                           AS uploaded,
                SUM(`s3_status` = 1 AND `s3_purged_at` IS NOT NULL)            AS purged,
                SUM(`s3_status` = 1 AND `s3_purged_at` IS NULL)                AS local_kept,
                SUM(`s3_status` = 2)                                           AS failed,
                SUM(`s3_status` = 0 AND `file_path` <> '')                     AS queued,
                SUM(`s3_remote_only` = 1)                                      AS remote_only,
                COALESCE(SUM(CASE WHEN `s3_status` = 1 THEN `s3_size` END), 0)  AS remote_bytes
             FROM `episode`"
        ) ?? [];

        $out = [];
        foreach ($row as $k => $v) {
            $out[$k] = (int) $v;
        }

        $free = @disk_free_space(RemuxService::downloadRoot());
        $out['disk_free'] = is_float($free) || is_int($free) ? (int) $free : null;

        return $out;
    }

    // ==================================================================
    //  上传
    // ==================================================================

    /**
     * 上传单集：选源 → 上传 → 回查校验 → 按配置删本地。
     *
     * @return array{ok:bool,skipped:bool,busy:bool,reason:?string,key:?string,bytes:int,error:?string,purged:bool}
     */
    public function upload(
        int $episodeId,
        bool $force = false,
        bool $skipLock = false,
        string $trigger = 'auto',
        ?string $actor = null
    ): array {
        $ep = Database::one(
            'SELECT `id`,`anime_id`,`ep_no`,`title`,`file_path`,`remux_path`,`remux_err`,
                    `s3_key`,`s3_status`,`s3_attempts`
             FROM `episode` WHERE `id` = :id LIMIT 1',
            ['id' => $episodeId]
        );
        if ($ep === null) {
            return self::res(false, false, false, 'episode_not_found', null, 0, '剧集不存在', false);
        }

        // ---- 选源：优先 remux 后的 MP4 ----
        $remux = (string) ($ep['remux_path'] ?? '');
        $orig  = (string) ($ep['file_path'] ?? '');
        $src   = '';
        if ($remux !== '' && is_file($remux)) {
            $src = $remux;
        } elseif ($orig !== '' && is_file($orig)) {
            if (Setting::get('s3_require_remux', '1') === '1'
                && ($ep['remux_err'] ?? null) === null
                && !MediaProbe::isNativeContainer(basename($orig))) {
                return self::res(false, true, false, 'waiting_remux', null, 0, null, false);
            }
            $src = $orig;
        } else {
            // 本地文件已不在：若远端有副本则视为已完成，否则报错
            if ((string) ($ep['s3_key'] ?? '') !== '' && (int) $ep['s3_status'] === 1) {
                return self::res(true, true, false, 'already_remote', (string) $ep['s3_key'], 0, null, false);
            }
            self::markFail($episodeId, '本地文件不存在且远端无副本');
            return self::res(false, false, false, 'file_missing', null, 0, '本地文件不存在且远端无副本', false);
        }

        if (!$force && (int) $ep['s3_status'] === 1 && (string) ($ep['s3_key'] ?? '') !== '') {
            return self::res(true, true, false, 'already_uploaded', (string) $ep['s3_key'], 0, null, false);
        }

        $size = (int) @filesize($src);
        if ($size <= 0) {
            self::markFail($episodeId, '源文件为空');
            return self::res(false, false, false, 'empty_file', null, 0, '源文件为空', false);
        }

        $key = (string) ($ep['s3_key'] ?? '');
        if ($key === '') {
            $key = S3::buildKey((int) $ep['anime_id'], (float) $ep['ep_no'], basename($src));
        }

        // ---- 串行化 ----
        $lock    = null;
        $lockErr = null;
        if (!$skipLock) {
            $lock = self::acquireLock($lockErr);
            if ($lock === null) {
                // 锁文件打不开 = 环境问题（例如被别的用户以 root 创建过），必须显性失败：
                // 以前和 "有人正在上传" 一起返回 busy，daemon 直接跳过且不记任何东西，
                // 结果上传任务一次都没跑，而所有测试都是绿的。
                if ($lockErr !== null) {
                    self::markFail($episodeId, $lockErr);
                    return self::res(false, false, false, 'lock_error', null, 0, $lockErr, false);
                }
                return self::res(false, false, true, 'busy', null, 0, null, false);
            }
        }

        // ---- 开一条任务记录（后台「上传任务」页靠它展示实时进度与历史）----
        $t0     = microtime(true);
        $taskId = S3TaskLog::start($episodeId, 'upload', [
            'source'      => basename($src),
            's3_key'      => $key,
            'bytes_total' => $size,
            'trigger'     => $trigger,
            'actor'       => $actor,
        ]);
        // 预估分片数：真实值由回调修正（小文件是单次 PUT，共 1 片）
        $estMultipart = $size > S3::PART_SIZE;
        S3TaskLog::plan(
            $taskId,
            $size,
            $estMultipart ? (int) ceil($size / S3::PART_SIZE) : 1,
            $estMultipart,
            $key
        );

        /** 失败收尾：写库 + 落任务日志（两处都要，缺一个就会出现「有失败没记录」） */
        $finishFail = static function (?string $msg) use ($taskId, $t0): void {
            S3TaskLog::finish($taskId, false, [
                'duration_ms' => (int) ((microtime(true) - $t0) * 1000),
                'error'       => $msg,
            ]);
        };

        try {
            // 8MB 分片 + curl 缓冲，给足余量
            @ini_set('memory_limit', '512M');

            $r = $this->s3->putFile($key, $src, null, static function (int $done, int $parts, int $total) use ($taskId): void {
                S3TaskLog::progress($taskId, $done, $parts, $total);
            });
            if (!$r['ok']) {
                self::markFail($episodeId, (string) $r['error']);
                $finishFail((string) $r['error']);
                return self::res(false, false, false, 'upload_failed', null, 0, (string) $r['error'], false);
            }

            // ---- 关键：回查远端大小，不一致绝不删本地 ----
            try {
                $h = $this->s3->head($key);
            } catch (\Throwable $e) {
                $msg = '上传后校验失败：' . $e->getMessage();
                self::markFail($episodeId, $msg);
                $finishFail($msg);
                return self::res(false, false, false, 'verify_failed', $key, 0, $msg, false);
            }
            if ($h === null) {
                self::markFail($episodeId, '上传后远端找不到对象');
                $finishFail('上传后远端找不到对象');
                return self::res(false, false, false, 'verify_missing', $key, 0, '上传后远端找不到对象', false);
            }
            if ((int) $h['size'] !== $size) {
                $msg = sprintf('远端大小与本地不一致（本地 %d / 远端 %d）', $size, (int) $h['size']);
                self::markFail($episodeId, $msg);
                $finishFail($msg);
                return self::res(false, false, false, 'size_mismatch', $key, 0, $msg, false);
            }

            Database::update('episode', [
                's3_key'        => $key,
                's3_size'       => $size,
                's3_status'     => 1,
                's3_err'        => null,
                's3_uploaded_at' => date('Y-m-d H:i:s'),
                's3_attempts'   => (int) $ep['s3_attempts'] + 1,
            ], '`id` = :id', ['id' => $episodeId]);

            // ---- 删本地 ----
            // 上传已经回查校验通过，本地删不掉只是「没省下磁盘」，不影响远端副本可用，
            // 所以任务仍算成功；但必须把原因记下来，否则用户会以为空间已经释放了。
            $purged   = false;
            $purgeErr = null;
            if (Setting::get('s3_delete_local', '1') === '1') {
                $pg       = $this->purgeLocal($episodeId, $src);
                $purged   = $pg['ok'];
                $purgeErr = $pg['error'];
                if (!$purged) {
                    Log::write('s3', '本地未清理干净', [
                        'episode' => $episodeId,
                        'error'   => $pg['error'],
                    ]);
                }
            }

            S3TaskLog::finish($taskId, true, [
                'duration_ms' => (int) ((microtime(true) - $t0) * 1000),
                'purged'      => $purged,
                'purge_error' => $purgeErr,
                'key'         => $key,
                'bytes_done'  => $size,
            ]);

            Log::write('s3', '上传成功', [
                'episode' => $episodeId, 'key' => $key, 'bytes' => $size,
                'multipart' => $r['multipart'], 'source' => basename($src),
                'purged' => $purged, 'seconds' => round(microtime(true) - $t0, 1),
            ]);

            return self::res(true, false, false, null, $key, $size, null, $purged, $purgeErr);
        } catch (\Throwable $e) {
            $msg = '上传异常：' . $e->getMessage();
            self::markFail($episodeId, $msg);
            S3TaskLog::finish($taskId, false, [
                'duration_ms' => (int) ((microtime(true) - $t0) * 1000),
                'error'       => $msg,
            ]);
            return self::res(false, false, false, 'exception', null, 0, $msg, false);
        } finally {
            if ($lock !== null) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * 删除本地文件（remux 产物 + 原始文件），并记录删除时间。
     *
     * 刻意保留 file_path / remux_path 字符串：清空会让 RemuxService 把这个
     * 已经删掉源文件的集重新排进 remux 队列，每轮都失败。
     *
     * **必须核对删除结果**：unlink 能否成功取决于**目录**的写权限，而下载目录里的文件
     * 往往是 qBittorrent 以另一个用户（本机是 root）写下的。以 www-data 常驻的 daemon
     * 去删 root 属主的文件时，`@unlink` 会静默失败 —— 如果这时照样写 s3_purged_at，
     * 就等于谎报「已经省下磁盘」，而实际上一个字节都没释放。所以这里：
     *   - 只有当本地已无任何残留视频文件时才写 s3_purged_at；
     *   - 有残留时返回失败原因，交给上层记进任务日志（后台能直接看到）。
     *
     * @param bool $force 即使 s3_purged_at 已有值也重新按文件系统实际情况清理。
     *                    用于修复历史欠账：早期版本在 unlink 静默失败时照样写了
     *                    s3_purged_at，于是「时间戳说已清理、文件其实还在」。
     * @return array{ok:bool,deleted:int,leftover:array<int,string>,error:?string}
     */
    public function purgeLocal(int $episodeId, ?string $alreadyDeleted = null, bool $force = false): array
    {
        $ep = Database::one(
            'SELECT `file_path`,`remux_path`,`s3_purged_at` FROM `episode` WHERE `id` = :id',
            ['id' => $episodeId]
        );
        if ($ep === null) {
            return ['ok' => false, 'deleted' => 0, 'leftover' => [], 'error' => '剧集不存在'];
        }
        if (!$force && ($ep['s3_purged_at'] ?? null) !== null) {
            return ['ok' => true, 'deleted' => 0, 'leftover' => [], 'error' => null];
        }

        $deleted = 0;
        foreach ([$ep['remux_path'], $ep['file_path']] as $p) {
            $p = (string) $p;
            if ($p === '' || !is_file($p)) {
                continue;
            }
            // 只允许删下载根目录内的文件
            if (!self::insideDownloadRoot($p)) {
                Log::write('s3', '拒绝删除下载根目录之外的文件', ['path' => $p]);
                continue;
            }
            if (@unlink($p)) {
                $deleted++;
            }
        }

        // 顺手清理该集的 remux 临时碎片
        $tmp = RemuxService::targetPath($episodeId);
        foreach (glob($tmp . '.tmp.*') ?: [] as $t) {
            @unlink($t);
        }

        // 复核：本地是否还有残留的视频文件
        $leftover = [];
        foreach ([$ep['remux_path'], $ep['file_path']] as $p) {
            $p = (string) $p;
            if ($p !== '' && is_file($p)) {
                $leftover[] = $p;
            }
        }

        if ($leftover) {
            $msg = '本地文件未能删除（' . count($leftover) . ' 个）：' . basename($leftover[0]);
            if (!is_writable(dirname($leftover[0]))) {
                $msg .= '；目录 ' . dirname($leftover[0]) . ' 对运行用户不可写（属主 '
                    . self::ownerOf(dirname($leftover[0])) . '，当前用户 ' . self::currentUser() . '）';
            }
            Log::write('s3', '本地文件删除失败，磁盘未释放', [
                'episode' => $episodeId,
                'leftover' => $leftover,
            ]);
            return ['ok' => false, 'deleted' => $deleted, 'leftover' => $leftover, 'error' => $msg];
        }

        // 原片所在目录若已空（单文件种子），一并收掉，避免留下空目录堆积
        $dir = dirname((string) $ep['file_path']);
        if ($dir !== '' && self::insideDownloadRoot($dir) && is_dir($dir)) {
            $left = @scandir($dir) ?: [];
            if (count(array_diff($left, ['.', '..'])) === 0) {
                @rmdir($dir);
            }
        }

        Database::update('episode', [
            's3_purged_at' => date('Y-m-d H:i:s'),
        ], '`id` = :id', ['id' => $episodeId]);

        Log::write('s3', '已删除本地文件', ['episode' => $episodeId, 'files' => $deleted]);
        return ['ok' => true, 'deleted' => $deleted, 'leftover' => [], 'error' => null];
    }

    /** 路径属主名，仅用于报错提示 */
    private static function ownerOf(string $path): string
    {
        $uid = @fileowner($path);
        if ($uid === false) {
            return '?';
        }
        return function_exists('posix_getpwuid')
            ? (posix_getpwuid($uid)['name'] ?? (string) $uid)
            : (string) $uid;
    }

    /** 当前进程用户，仅用于报错提示 */
    private static function currentUser(): string
    {
        if (function_exists('posix_geteuid')) {
            $eu = posix_geteuid();
            return function_exists('posix_getpwuid')
                ? (posix_getpwuid($eu)['name'] ?? (string) $eu)
                : (string) $eu;
        }
        return get_current_user() ?: '?';
    }

    /** 路径必须落在 download_root 内（防误删） */
    private static function insideDownloadRoot(string $path): bool
    {
        $root = realpath(RemuxService::downloadRoot());
        if ($root === false) {
            return false;
        }
        // 目标可能已不存在，用 dirname 的 realpath 兜底
        $real = realpath($path);
        if ($real === false) {
            $real = realpath(dirname($path));
            if ($real === false) {
                return false;
            }
        }
        return $real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR);
    }

    // ==================================================================
    //  远程内容
    // ==================================================================

    /**
     * 列出远程对象，并标注是否已被某集引用。
     *
     * @return array{ok:bool,objects:array,next:?string,truncated:bool,error:?string}
     */
    public function remoteList(string $prefix = '', ?string $token = null, int $limit = 100): array
    {
        $prefix = $prefix !== '' ? $prefix : S3::prefix();
        $prefix = trim($prefix, '/');
        if ($prefix !== '') {
            $prefix .= '/';
        }

        $r = $this->s3->listObjects($prefix, $token, $limit);
        if (!$r['ok']) {
            return $r;
        }

        // 标注引用状态：一次查出本页所有 key 的占用情况，避免 N 次查询
        $keys = array_column($r['objects'], 'key');
        $used = [];
        if ($keys) {
            $ph = implode(',', array_fill(0, count($keys), '?'));
            $rows = Database::all(
                "SELECT `s3_key`,`id`,`anime_id`,`ep_no` FROM `episode` WHERE `s3_key` IN ({$ph})",
                $keys
            );
            foreach ($rows as $row) {
                $used[(string) $row['s3_key']] = [
                    'episode_id' => (int) $row['id'],
                    'anime_id'   => (int) $row['anime_id'],
                    'ep_no'      => (float) $row['ep_no'],
                ];
            }
        }

        foreach ($r['objects'] as &$o) {
            $o['used'] = $used[$o['key']] ?? null;
            $o['name'] = basename((string) $o['key']);
            $o['folder'] = trim(str_replace($prefix, '', dirname((string) $o['key'])), '/');
        }
        unset($o);

        return $r;
    }

    /**
     * 补档：直接把一个远程对象登记为该番剧的一集（不下载）。
     *
     * @param array{title?:string,ep_no?:float|int|string,resolution?:string} $opt
     * @return array{ok:bool,episode_id:?int,ep_no:?float,error:?string}
     */
    public function importRemote(int $animeId, string $key, array $opt = []): array
    {
        $key = trim($key);
        if ($key === '') {
            return ['ok' => false, 'episode_id' => null, 'ep_no' => null, 'error' => '对象键为空'];
        }
        $key = ltrim(str_replace('\\', '/', $key), '/');

        // 必须在配置的前缀内，避免把桶里别的东西引进来
        $prefix = S3::prefix();
        if ($prefix !== '' && !str_starts_with($key, $prefix . '/')) {
            return ['ok' => false, 'episode_id' => null, 'ep_no' => null, 'error' => "对象必须位于 {$prefix}/ 前缀下"];
        }

        if (Database::val('SELECT 1 FROM `episode` WHERE `s3_key` = :k LIMIT 1', ['k' => $key]) !== null) {
            return ['ok' => false, 'episode_id' => null, 'ep_no' => null, 'error' => '该对象已被某一集引用'];
        }

        try {
            $h = $this->s3->head($key);
        } catch (\Throwable $e) {
            return ['ok' => false, 'episode_id' => null, 'ep_no' => null, 'error' => '读取对象失败：' . $e->getMessage()];
        }
        if ($h === null) {
            return ['ok' => false, 'episode_id' => null, 'ep_no' => null, 'error' => '远端对象不存在'];
        }

        $name = basename($key);
        $title = trim((string) ($opt['title'] ?? ''));
        if ($title === '') {
            $title = $name;
        }

        // 集数：显式传入优先，否则从文件名解析
        $epNo = isset($opt['ep_no']) && (string) $opt['ep_no'] !== '' ? (float) $opt['ep_no'] : null;
        $parsed = TitleParser::parse($name);
        if (($epNo === null || $epNo <= 0) && $parsed['ep_no'] !== null) {
            $epNo = (float) $parsed['ep_no'];
        }
        if ($epNo === null || $epNo <= 0) {
            // 退一步：取文件名里第一个独立数字
            if (preg_match('/(?:^|[\s_\-\[\(])(\d{1,4})(?:[\s_\-v\]\)]|$)/', $name, $m)) {
                $epNo = (float) $m[1];
            }
        }
        if ($epNo === null || $epNo <= 0) {
            return ['ok' => false, 'episode_id' => null, 'ep_no' => null, 'error' => '无法从文件名识别集数，请手动填写'];
        }

        $resolution = trim((string) ($opt['resolution'] ?? '')) ?: ($parsed['resolution'] ?? null);

        $id = Database::insert('episode', [
            'anime_id'      => $animeId,
            'ep_no'         => $epNo,
            'title'         => mb_substr($title, 0, 500),
            'file_path'     => '',          // 本地从未落地
            'file_size'     => (int) $h['size'],
            'resolution'    => $resolution,
            'subtitle_lang' => $parsed['subtitle_lang'],
            's3_key'        => $key,
            's3_size'       => (int) $h['size'],
            's3_status'     => 1,
            's3_uploaded_at' => date('Y-m-d H:i:s'),
            's3_attempts'   => 0,
            's3_remote_only' => 1,
        ]);

        (new SyncService())->refreshAnime($animeId);

        // 记一条任务日志：补档不改动远端，但「什么时候引用了什么」同样值得留痕
        $taskId = S3TaskLog::start($id, 'import_remote', [
            'source'      => $name,
            's3_key'      => $key,
            'bytes_total' => (int) $h['size'],
            'trigger'     => ($opt['trigger'] ?? 'auto') === 'manual' ? 'manual' : 'auto',
            'actor'       => $opt['actor'] ?? null,
        ]);
        S3TaskLog::finish($taskId, true, [
            'key'        => $key,
            'bytes_done' => (int) $h['size'],
        ]);

        Log::write('s3', '远程补档引用成功', ['episode' => $id, 'anime' => $animeId, 'key' => $key]);

        return ['ok' => true, 'episode_id' => $id, 'ep_no' => $epNo, 'error' => null];
    }

    /** 删除某个剧集的远程副本（不动本地文件，也不动 DB 记录之外的资源） */
    public function deleteObject(string $key): bool
    {
        $key = trim($key);
        if ($key === '') {
            return false;
        }
        return $this->s3->delete($key);
    }

    /** 解除某集的对象存储关联（本地文件保留） */
    public static function unlink(int $episodeId): void
    {
        Database::update('episode', [
            's3_key'        => null,
            's3_size'       => 0,
            's3_status'     => 0,
            's3_err'        => null,
            's3_uploaded_at' => null,
            's3_purged_at'  => null,
            's3_attempts'   => 0,
            's3_remote_only' => 0,
        ], '`id` = :id', ['id' => $episodeId]);
    }

    // ==================================================================
    //  内部
    // ==================================================================

    /**
     * 取上传锁。返回 null 时通过 $error 区分两种情况：
     *   - $error === null → 有别的上传正在跑（正常，调用方应跳过本轮）
     *   - $error !== null → 锁文件本身不可用（环境问题，必须显性报错）
     */
    private static function acquireLock(?string &$error = null): mixed
    {
        $error = null;
        $dir = RemuxService::downloadRoot() . '/remux';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $fp = FileLock::open($dir . '/.s3upload.lock', $error);
        if ($fp === null) {
            return null;   // $error 已由 FileLock 填好
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return null;   // $error 保持 null → 视为「有人在跑」
        }
        return $fp;
    }

    private static function markFail(int $episodeId, string $msg): void
    {
        Database::run(
            'UPDATE `episode`
                SET `s3_status` = 2,
                    `s3_err`    = :e,
                    `s3_attempts` = `s3_attempts` + 1
              WHERE `id` = :id',
            ['e' => mb_substr($msg, 0, 250), 'id' => $episodeId]
        );
        Log::write('s3', '上传失败', ['episode' => $episodeId, 'error' => mb_substr($msg, 0, 200)]);
    }

    /** @return array{ok:bool,skipped:bool,busy:bool,reason:?string,key:?string,bytes:int,error:?string,purged:bool,purge_error:?string} */
    private static function res(
        bool $ok,
        bool $skipped,
        bool $busy,
        ?string $reason,
        ?string $key,
        int $bytes,
        ?string $error,
        bool $purged = false,
        ?string $purgeError = null
    ): array {
        return [
            'ok' => $ok, 'skipped' => $skipped, 'busy' => $busy, 'reason' => $reason,
            'key' => $key, 'bytes' => $bytes, 'error' => $error, 'purged' => $purged,
            'purge_error' => $purgeError,
        ];
    }
}
