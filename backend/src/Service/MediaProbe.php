<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Database;
use App\Core\Setting;

/**
 * 片源能力探测：调 ffprobe 读出真实编码信息并落到 episode 表。
 *
 * 为什么不能只看文件后缀：浏览器能不能播由三层共同决定
 *   1. 容器   —— mkv / avi / ts 浏览器不认（可由前端解封装成 fMP4 绕过）
 *   2. 视频编码 —— hevc 只有带硬件解码的环境才放得动，Firefox 完全不支持
 *   3. 像素格式 —— 10bit 对解码器要求更高（Chrome 需 >= 108）
 * 只看后缀只能判断第一层，所以必须探测。
 */
final class MediaProbe
{
    /** 探测结果骨架 */
    private const FIELDS = ['vcodec', 'acodec', 'pix_fmt', 'sub_tracks', 'error'];

    /** 浏览器原生可播的容器后缀 */
    private const NATIVE_EXT = ['mp4', 'm4v', 'webm', 'mov'];

    /** 常见的封面图编码（附在 mkv 里，不能当主视频轨） */
    private const COVER_CODECS = ['mjpeg', 'png', 'bmp', 'gif', 'webp'];

    public static function bin(): string
    {
        $p = trim((string) Setting::get('ffprobe_path', ''));
        return $p !== '' ? $p : 'ffprobe';
    }

    // ------------------------------------------------------------------
    // 探测
    // ------------------------------------------------------------------

    /**
     * 探测单个文件。任何异常都收敛成 error 字段返回，绝不抛。
     */
    public static function probeFile(string $path): array
    {
        $out = array_fill_keys(self::FIELDS, null) + ['sub_tracks' => 0];

        if (!is_file($path)) {
            $out['error'] = '文件不存在';
            return $out;
        }

        $cmd = sprintf(
            'timeout 20 %s -v error -print_format json -show_streams %s 2>/dev/null',
            escapeshellcmd(self::bin()),
            escapeshellarg($path)
        );
        $raw = @shell_exec($cmd);

        if (!is_string($raw) || trim($raw) === '') {
            $out['error'] = 'ffprobe 无输出（文件损坏或探测超时）';
            return $out;
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || !isset($json['streams']) || !is_array($json['streams'])) {
            $out['error'] = 'ffprobe 输出无法解析';
            return $out;
        }

        $subs = 0;
        foreach ($json['streams'] as $s) {
            if (!is_array($s)) {
                continue;
            }
            $type = (string) ($s['codec_type'] ?? '');
            $name = strtolower((string) ($s['codec_name'] ?? ''));

            if ($type === 'video' && $out['vcodec'] === null) {
                $isCover = (int) ($s['disposition']['attached_pic'] ?? 0) === 1
                    || in_array($name, self::COVER_CODECS, true);
                if ($isCover) {
                    continue; // 封面图不是主视频轨，接着找
                }
                $out['vcodec']  = $name !== '' ? $name : null;
                $out['pix_fmt'] = isset($s['pix_fmt']) ? strtolower((string) $s['pix_fmt']) : null;
            } elseif ($type === 'audio' && $out['acodec'] === null) {
                $out['acodec'] = $name !== '' ? $name : null;
            } elseif ($type === 'subtitle') {
                $subs++;
            }
        }
        $out['sub_tracks'] = $subs;

        if ($out['vcodec'] === null) {
            $out['error'] = '未找到视频轨';
        }

        return $out;
    }

    /**
     * 探测并写回 episode 表。
     *
     * @return array{ok:bool,skipped?:bool,error?:string|null}
     */
    public static function probeEpisode(int $episodeId, bool $force = false): array
    {
        $row = Database::one(
            'SELECT `id`,`file_path`,`probe_at` FROM `episode` WHERE `id` = :id LIMIT 1',
            ['id' => $episodeId]
        );
        if ($row === null) {
            return ['ok' => false, 'error' => '剧集不存在'];
        }
        if (!$force && $row['probe_at'] !== null) {
            return ['ok' => true, 'skipped' => true];
        }

        $r = self::probeFile((string) $row['file_path']);

        Database::update('episode', [
            'vcodec'      => $r['vcodec'],
            'acodec'      => $r['acodec'],
            'pix_fmt'     => $r['pix_fmt'],
            'sub_tracks'  => (int) $r['sub_tracks'],
            'probe_at'    => date('Y-m-d H:i:s'),
            'probe_error' => $r['error'],
        ], '`id` = :id', ['id' => $episodeId]);

        return ['ok' => $r['error'] === null, 'error' => $r['error']];
    }

    // ------------------------------------------------------------------
    // 能力判定
    // ------------------------------------------------------------------

    /** 容器能否直接被浏览器 <video> 播放 */
    public static function isNativeContainer(string $filename): bool
    {
        return in_array(strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)), self::NATIVE_EXT, true);
    }

    /** 像素格式是否 10bit 及以上 */
    public static function isHighBitDepth(?string $pixFmt): bool
    {
        return $pixFmt !== null && (bool) preg_match('/(?:10|12|14|16)(?:le|be)?$/', $pixFmt);
    }

    /** 视频编码是否依赖硬件解码（browser 侧能力未知，需要前端二次确认） */
    public static function needsHardwareDecode(?string $vcodec): bool
    {
        return $vcodec !== null && in_array($vcodec, ['hevc', 'h265'], true);
    }

    /**
     * 给前端的一组判定结论。前端据此选通路，并给出精确的失败原因。
     */
    public static function playbackPlan(array $ep): array
    {
        $name   = (string) ($ep['file_name'] ?? '');
        $vcodec = $ep['vcodec'] ?? null;
        $pixFmt = $ep['pix_fmt'] ?? null;
        $acodec = $ep['acodec'] ?? null;

        $native    = self::isNativeContainer($name);
        $probed    = ($ep['probe_at'] ?? null) !== null;
        $hevc      = self::needsHardwareDecode($vcodec);
        $highDepth = self::isHighBitDepth($pixFmt);

        return [
            // direct: 原生 <video src>；remux: 前端解封装成 fMP4 再喂 MSE
            'mode'        => $native ? 'direct' : 'remux',
            'probed'      => $probed,
            'hevc'        => $hevc,
            'high_depth'  => $highDepth,
            // 音频是否需要前端转码（playsvideo 只对不安全音频动 ffmpeg.wasm）
            'audio_remux' => $acodec !== null && !in_array($acodec, ['aac', 'mp3', 'opus', 'vorbis'], true),
            // 给用户看的说明
            'note'        => self::note($native, $vcodec, $pixFmt, $acodec, $probed),
        ];
    }

    private static function note(bool $native, ?string $vcodec, ?string $pixFmt, ?string $acodec, bool $probed): ?string
    {
        if (!$probed) {
            return null;
        }
        $v = $vcodec !== null ? strtoupper($vcodec) : '未知视频编码';
        if (self::isHighBitDepth($pixFmt) && preg_match('/(\d{2})(?:le|be)?$/', (string) $pixFmt, $m)) {
            $v .= ' ' . (int) $m[1] . 'bit';
        }
        $a = $acodec !== null ? strtoupper($acodec) : '未知音频';

        $parts = [$v . ' / ' . $a];
        if (!$native) {
            $parts[] = '前端解封装播放';
        }
        if (self::needsHardwareDecode($vcodec)) {
            $parts[] = '需浏览器硬件解码（Chrome / Edge 可用，Firefox 不支持）';
        }
        return implode(' · ', $parts);
    }
}
