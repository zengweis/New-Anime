<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Log;
use App\Core\Setting;

/**
 * 位图字幕 → 图片叠加层。
 *
 * 与 SubtitleOcr 的关系：两者都是「位图字幕怎么办」的答案，机制不同、各有取舍：
 *
 *   SubtitleImage（本类）  PGS 自带「显示时刻 + 位置 + 调色板」，直接渲成透明图片，
 *                          前端按时间叠加在视频上。100% 保真（原样式/特效/位置），
 *                          一集约 1~2 分钟 CPU、10MB 左右图片。
 *   SubtitleOcr           逐帧 OCR 成文本。可选文本、可搜索，但一集约 8 分钟 CPU，
 *                          且片头曲、特效字幕、画面标牌会产生错字（那些本来就是图形）。
 *
 * 为什么渲染也必须异步：
 *   实测一轨 1009 个 display set 要 100 秒左右（RLE 解码 + WebP 编码 + 逐屏比较）。
 *   常驻进程主循环是串行的，同步做会把 remux / 上传 / 删本地一起堵住。所以和 OCR 一样：
 *   本类只负责「起一个脱离父进程的渲染进程」与「判断产物是否就绪」。
 *
 * 产物布局（放在该集的字幕缓存目录下）：
 *   /data/anime-subs/<ep>/img<streamIndex>/00001.webp …
 *   /data/anime-subs/<ep>/img<streamIndex>/manifest.json
 */
final class SubtitleImage
{
    /** 单个渲染进程最长允许时间（秒） */
    public const TIMEOUT = 1800;

    /** 认为「进程已死、标记可清理」的阈值（秒） */
    private const STALE_MARKER = 3600;

    private const MAX_ATTEMPTS = 2;

    /** 渲染脚本（随应用部署） */
    public static function script(): string
    {
        return APP_ROOT . '/bin/pgs_to_images.py';
    }

    /** Python 解释器：用装了 cv2/numpy/pgsrip 的那个 venv */
    public static function python(): string
    {
        $p = trim((string) Setting::get('subs_py_bin', ''));
        if ($p !== '' && is_file($p)) {
            return $p;
        }
        // pgsrip 的 venv 里同时有 cv2、numpy、pgsrip，复用它即可
        $bin = trim((string) Setting::get('subs_ocr_bin', ''));
        if ($bin !== '') {
            $guess = dirname($bin) . '/python';
            if (is_file($guess)) {
                return $guess;
            }
        }
        return '/opt/anime-new/venv-ocr/bin/python';
    }

    /**
     * @return array{ok:bool,reason:string,python:string,script:string}
     */
    public static function available(): array
    {
        $py  = self::python();
        $sh  = self::script();
        $ok  = is_file($py) && is_executable($py) && is_file($sh);

        $reason = '';
        if (!is_file($py)) {
            $reason = '未找到 Python 解释器：' . $py . '（需要 pgsrip 的 venv，内含 cv2/numpy）';
        } elseif (!is_file($sh)) {
            $reason = '未找到渲染脚本：' . $sh;
        }

        return ['ok' => $ok, 'reason' => $reason, 'python' => $py, 'script' => $sh];
    }

    /** 某条流对应的输出目录 */
    public static function dirFor(string $episodeDir, int $streamIndex): string
    {
        return $episodeDir . '/img' . $streamIndex;
    }

    public static function manifestFor(string $episodeDir, int $streamIndex): string
    {
        return self::dirFor($episodeDir, $streamIndex) . '/manifest.json';
    }

    /** 清单是否已就绪（有文件且能解析出 cues） */
    public static function ready(string $episodeDir, int $streamIndex): bool
    {
        $m = self::manifestFor($episodeDir, $streamIndex);
        if (!is_file($m) || (int) @filesize($m) < 32) {
            return false;
        }
        $d = json_decode((string) @file_get_contents($m), true);
        return is_array($d) && !empty($d['cues']);
    }

    /** 渲染是否正在进行 */
    public static function running(string $episodeDir, int $streamIndex): bool
    {
        $mark = self::manifestFor($episodeDir, $streamIndex) . '.running';
        if (!is_file($mark)) {
            return false;
        }
        if (time() - (int) @filemtime($mark) > self::STALE_MARKER) {
            @unlink($mark);          // 进程被杀（重启/超时）留下的陈标记
            return false;
        }
        return true;
    }

    /** 渲染是否已失败到底（避免无限重试） */
    public static function exhausted(string $episodeDir, int $streamIndex): bool
    {
        $mark = self::manifestFor($episodeDir, $streamIndex) . '.running';
        $log  = self::manifestFor($episodeDir, $streamIndex) . '.log';
        if (!is_file($log) || self::ready($episodeDir, $streamIndex) || self::running($episodeDir, $streamIndex)) {
            return false;
        }
        $cntFile = self::manifestFor($episodeDir, $streamIndex) . '.attempts';
        $cnt = (int) @file_get_contents($cntFile);
        if ($cnt >= self::MAX_ATTEMPTS) {
            return true;
        }
        @file_put_contents($cntFile, (string) ($cnt + 1));
        return false;
    }

    /**
     * 起一个**脱离父进程**的渲染进程。
     *
     * @param array{format?:string,threshold?:float,quality?:int} $opt
     * @return array{ok:bool,spawned:bool,error:?string}
     */
    public static function spawn(string $sup, string $episodeDir, int $streamIndex, array $opt = []): array
    {
        $outDir = self::dirFor($episodeDir, $streamIndex);
        if (self::ready($episodeDir, $streamIndex)) {
            return ['ok' => true, 'spawned' => false, 'error' => null];
        }

        $avail = self::available();
        if (!$avail['ok']) {
            return ['ok' => false, 'spawned' => false, 'error' => $avail['reason']];
        }

        if (!is_dir($outDir) && !@mkdir($outDir, 0775, true)) {
            return ['ok' => false, 'spawned' => false, 'error' => '无法创建目录：' . $outDir];
        }

        $log  = self::manifestFor($episodeDir, $streamIndex) . '.log';
        $mark = self::manifestFor($episodeDir, $streamIndex) . '.running';
        @touch($mark);

        $inner = sprintf(
            'timeout %d %s %s %s %s --format=%s --threshold=%s --quality=%d > %s 2>&1; rm -f %s',
            self::TIMEOUT,
            escapeshellcmd($avail['python']),
            escapeshellarg($avail['script']),
            escapeshellarg($sup),
            escapeshellarg($outDir),
            escapeshellarg((string) ($opt['format'] ?? Setting::get('subs_img_format', 'webp'))),
            escapeshellarg((string) ($opt['threshold'] ?? Setting::get('subs_img_threshold', '0.02'))),
            (int) ($opt['quality'] ?? Setting::get('subs_img_quality', '90')),
            escapeshellarg($log),
            escapeshellarg($mark)
        );
        @exec('setsid bash -c ' . escapeshellarg($inner) . ' > /dev/null 2>&1 < /dev/null &');

        usleep(400000);
        if (!is_file($mark)) {
            return ['ok' => false, 'spawned' => false, 'error' => '渲染进程未能启动'];
        }

        Log::write('subs', '位图字幕渲染已转入后台', [
            'sup' => basename($sup), 'out' => basename($outDir),
        ]);
        return ['ok' => true, 'spawned' => true, 'error' => null];
    }

    /** 读清单（供分发接口使用），返回 null 表示不可用 */
    public static function manifest(string $episodeDir, int $streamIndex): ?array
    {
        $m = self::manifestFor($episodeDir, $streamIndex);
        if (!is_file($m)) {
            return null;
        }
        $d = json_decode((string) @file_get_contents($m), true);
        return is_array($d) ? $d : null;
    }

    /** 图片文件是否被允许访问（文件名白名单，防目录穿越） */
    public static function allowedImageName(string $name): bool
    {
        return preg_match('/^[0-9]{3,6}\.(?:webp|png|jpg)$/', $name) === 1;
    }
}
