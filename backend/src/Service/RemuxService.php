<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Database;
use App\Core\FileLock;
use App\Core\Setting;

/**
 * 服务端 remux：把 MKV 一次性 remux 成 MP4（ffmpeg -c copy，零转码），
 * 浏览器原生 <video> 播放，绕开前端解封装（playsvideo）的 segment 边界
 * 音频时间戳跳变导致的「音频隔几秒断 0.x 秒」问题。
 *
 * 为什么放服务端而不是前端 wasm（mkv-web 路线）：
 *   - mkv-web 用 ffmpeg.wasm 需要 SharedArrayBuffer，而 SharedArrayBuffer 强制
 *     HTTPS/localhost；本项目是 HTTP 裸 IP，COOP/COEP 头被 Chrome 直接忽略。
 *   - 服务端 ffmpeg 是同一个成熟 muxer，音频时间戳同样正确，但零 wasm 负担。
 *   - 实测 60s 片源 remux 仅 0.176s，整集 617MB 约 4s，纯磁盘 I/O，CPU 几乎为 0。
 *
 * 关键取舍：
 *   - `-c copy` 音视频都复制不重编码；HEVC 加 `-tag:v hvc1` 把参数集写进 hvcC
 *     box，否则 Chrome addSourceBuffer / 原生播放会因 hev1(in-band) 而识别失败。
 *   - 字幕不 remux（已经由 SubtitleService 抽成 WebVTT 单独分发）。
 *   - 结果按 episode_id 缓存，源文件 mtime 变了才重做。
 *   - flock 串行化，防止多个首次播放请求同时 remux 把磁盘打满。
 */
final class RemuxService
{
    /** 单集 remux 超时（秒）。617MB 约 4s，留足余量应对大文件或慢盘 */
    private const FFMPEG_TIMEOUT = 600;

    // ------------------------------------------------------------------
    // 路径
    // ------------------------------------------------------------------

    /** 下载根目录（与 stream.php / nginx /protected 保持一致） */
    public static function downloadRoot(): string
    {
        $r = trim((string) Setting::get('download_root', '/data/downloads/anime'));
        return rtrim(str_replace('\\', '/', $r), '/');
    }

    /** remux 产物根目录：{download_root}/remux */
    public static function root(): string
    {
        return self::downloadRoot() . '/remux';
    }

    public static function ffmpegBin(): string
    {
        $p = trim((string) Setting::get('ffmpeg_path', ''));
        return $p !== '' ? $p : 'ffmpeg';
    }

    private static function ensureRoot(): bool
    {
        $root = self::root();
        if (is_dir($root)) {
            return is_writable($root);
        }
        return @mkdir($root, 0775, true) || (is_dir($root) && is_writable($root));
    }

    /** 单集的 remux 目标文件 */
    public static function targetPath(int $episodeId): string
    {
        return self::root() . '/' . $episodeId . '.mp4';
    }

    // ------------------------------------------------------------------
    // remux
    // ------------------------------------------------------------------

    /**
     * remux 单集。幂等：源文件未变且已有 MP4 则直接返回。
     *
     * @return array{ok:bool,path:?string,reason:?string,error:?string,skipped:bool,busy:bool}
     */
    public static function remux(int $episodeId, bool $force = false): array
    {
        $ep = Database::one(
            'SELECT `id`,`file_path`,`vcodec`,`remux_path` FROM `episode` WHERE `id` = :id LIMIT 1',
            ['id' => $episodeId]
        );
        if ($ep === null) {
            return self::result(false, null, 'episode_not_found', '剧集不存在');
        }

        $src = (string) $ep['file_path'];
        if ($src === '' || !is_file($src)) {
            self::mark($episodeId, null, '源文件不存在或已被删除');
            return self::result(false, null, 'file_missing', '源文件不存在或已被删除');
        }

        // 源本身就是浏览器原生容器（mp4/webm/mov）→ 无需 remux
        if (MediaProbe::isNativeContainer(basename($src))) {
            self::mark($episodeId, $src, null);
            return self::result(true, $src, 'native', null, true);
        }

        $dst = self::targetPath($episodeId);

        // 幂等：已有 MP4 且比源文件新，直接用
        if (!$force && is_file($dst) && (int) @filemtime($dst) >= (int) @filemtime($src)) {
            return self::result(true, $dst, 'cached', null, true);
        }

        if (!self::ensureRoot()) {
            $msg = 'remux 目录不可写：' . self::root();
            self::mark($episodeId, null, $msg);
            return self::result(false, null, 'root_unwritable', $msg);
        }

        // ---- 串行化：同一时刻只允许一路 remux ----
        $lockErr = null;
        $lock = FileLock::open(self::root() . '/.remux.lock', $lockErr);
        if ($lock === null) {
            return self::result(false, null, 'lock_unavailable', '无法创建锁文件：' . (string) $lockErr);
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return self::result(false, null, 'busy', null, false, true);
        }

        try {
            // 拿到锁后再查一次：可能刚被别的请求 remux 完
            if (!$force && is_file($dst) && (int) @filemtime($dst) >= (int) @filemtime($src)) {
                return self::result(true, $dst, 'cached', null, true);
            }

            // 先写临时文件再原子改名，避免留下半截 MP4 被当成成品
            $tmp = $dst . '.tmp.' . getmypid();
            @unlink($tmp);

            $r = self::runFfmpeg($src, (string) $ep['vcodec'], $tmp);
            if (!$r['ok']) {
                @unlink($tmp);
                self::mark($episodeId, null, $r['error']);
                return self::result(false, null, 'remux_failed', $r['error']);
            }

            if (!@rename($tmp, $dst)) {
                @unlink($tmp);
                $msg = '无法落盘 remux 产物：' . $dst;
                self::mark($episodeId, null, $msg);
                return self::result(false, null, 'rename_failed', $msg);
            }

            self::mark($episodeId, $dst, null);
            return self::result(true, $dst, 'remuxed');
        } catch (\Throwable $e) {
            $msg = 'remux 异常：' . $e->getMessage();
            self::mark($episodeId, null, mb_substr($msg, 0, 200));
            return self::result(false, null, 'exception', $msg);
        } finally {
            @unlink($dst . '.tmp.' . getmypid());
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function runFfmpeg(string $src, string $vcodec, string $dst): array
    {
        // 只 copy 不重编码；HEVC 强制 hvc1 tag（参数集进 hvcC box），
        // 否则 ffmpeg 默认输出 hev1(in-band)，Chrome 原生播放识别失败。
        $tag = in_array(strtolower($vcodec), ['hevc', 'h265'], true) ? ['-tag:v', 'hvc1'] : [];

        $cmd = 'timeout ' . self::FFMPEG_TIMEOUT
            . ' ' . escapeshellcmd(self::ffmpegBin())
            . ' -nostdin -v error -y -i ' . escapeshellarg($src)
            . ' -map 0:v:0 -map 0:a:0?'
            . ' -c copy'
            . ($tag ? ' ' . implode(' ', array_map('escapeshellarg', $tag)) : '')
            // 临时文件扩展名不是 .mp4，必须显式 -f mp4 让 ffmpeg 选对 muxer
            . ' -f mp4 -movflags +faststart'
            . ' ' . escapeshellarg($dst)
            . ' 2>&1';

        $lines = [];
        $rc    = 0;
        @exec($cmd, $lines, $rc);

        if ($rc !== 0 || !is_file($dst) || (int) @filesize($dst) === 0) {
            $tail = trim(implode(' ', array_slice($lines, -3)));
            return ['ok' => false, 'error' => $tail !== '' ? $tail : ('ffmpeg 退出码 ' . $rc)];
        }
        return ['ok' => true, 'error' => null];
    }

    private static function mark(int $episodeId, ?string $path, ?string $err): void
    {
        Database::update('episode', [
            'remux_path' => $path,
            'remux_err'  => $err,
        ], '`id` = :id', ['id' => $episodeId]);
    }

    private static function result(
        bool $ok,
        ?string $path,
        ?string $reason = null,
        ?string $error = null,
        bool $skipped = false,
        bool $busy = false
    ): array {
        return [
            'ok'      => $ok,
            'path'    => $path,
            'reason'  => $reason,
            'error'   => $error,
            'skipped' => $skipped,
            'busy'    => $busy,
        ];
    }

    // ------------------------------------------------------------------
    // 取文件（供 HTTP 分发）与待处理
    // ------------------------------------------------------------------

    /**
     * 定位 remux 后的 MP4。路径必须在 remux 根目录内，防目录穿越。
     * 返回 null 表示没有可用的 MP4。
     */
    public static function mp4Path(int $episodeId): ?string
    {
        $row = Database::one(
            'SELECT `remux_path` FROM `episode` WHERE `id` = :id LIMIT 1',
            ['id' => $episodeId]
        );
        $path = $row !== null ? (string) $row['remux_path'] : '';
        if ($path === '' || !is_file($path)) {
            return null;
        }

        $real = realpath($path);
        $base = realpath(self::root());
        if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return is_file($real) ? $real : null;
    }

    /**
     * 待 remux 的剧集：已探测、非原生容器、还没 remux。
     *
     * `s3_purged_at IS NULL` 这个条件不能少：本地文件上传到对象存储后被主动删除，
     * 但 remux_path / file_path 字符串是刻意保留的（清空会让这一集被重新排队）。
     * 如果 remux 曾经失败（remux_path 仍为 NULL），没有这个条件的话 daemon 会每轮
     * 都拿一个已经不存在的文件去跑 ffmpeg，stable 地失败并刷日志。
     */
    public static function pending(int $limit = 3): array
    {
        $limit = max(1, min(200, $limit));
        return Database::all(
            'SELECT `id`,`file_path`,`vcodec`
             FROM `episode`
             WHERE `remux_path` IS NULL
               AND `probe_at` IS NOT NULL
               AND `file_path` <> \'\'
               AND `s3_purged_at` IS NULL
             ORDER BY `id` DESC
             LIMIT ' . $limit
        );
    }

    /** 删掉某集的 remux 产物与记录 */
    public static function purge(int $episodeId): void
    {
        @unlink(self::targetPath($episodeId));
        Database::update('episode', [
            'remux_path' => null,
            'remux_err'  => null,
        ], '`id` = :id', ['id' => $episodeId]);
    }
}
