<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Database;
use App\Core\FileLock;
use App\Core\Log;
use App\Core\Setting;

/**
 * 内嵌字幕抽取：把 mkv 里的字幕轨一次性抽成 WebVTT 落盘缓存。
 *
 * 为什么放服务端而不是前端：
 *   前端解封装引擎要从头扫全片才能读出字幕轨，实测约 1.26x 实时
 *   （一集的 23 分钟片要等约 19 分钟才出字幕，等于看完才出）。
 *   服务端 ffmpeg 只解封装、不解视频码，实测 0.48 秒，且结果可复用。
 *   2c4g 纯 CPU 的机器上这点开销完全可以忽略——瓶颈是磁盘不是 CPU。
 *
 * 关键取舍：
 *   - 只做「解封装 + 转文本格式」，绝不做视频转码，所以几乎不吃 CPU。
 *   - 位图字幕（PGS / DVD / DVB）本质是一帧一张图，WebVTT 表达不了，
 *     除非上 OCR。直接标记为不可转并给出原因，不做无谓尝试。
 *   - 抽取结果按 episode_id 缓存，源文件 mtime 变了才重抽。
 *   - 全程用 flock 串行化，防止多人同时点播放把 2 个核打满。
 */
final class SubtitleService
{
    /** 可以转成 WebVTT 的文本字幕编码 */
    private const TEXT_CODECS = [
        'subrip', 'srt', 'ass', 'ssa', 'mov_text', 'webvtt', 'text',
        'eia_608', 'microdvd', 'jacosub', 'sami', 'realtext',
        'subviewer', 'subviewer1', 'vplayer', 'pjs', 'stl', 'ttml',
    ];

    /** 位图字幕：转不了，别浪费时间 */
    private const BITMAP_CODECS = [
        'hdmv_pgs_subtitle', 'pgssub', 'dvd_subtitle', 'dvdsub',
        'dvb_subtitle', 'dvbsub', 'xsub', 'dvb_teletext',
    ];

    /** 单轨抽取超时（秒）。一集 0.5 秒，留足余量应对大文件或慢盘 */
    private const FFMPEG_TIMEOUT = 300;

    /** 抽取失败后的冷却时间（秒），避免每次播放都重试同一个坏文件 */
    private const RETRY_COOLDOWN = 3600;

    // ------------------------------------------------------------------
    // 路径与外部命令
    // ------------------------------------------------------------------

    /** 字幕缓存根目录 */
    public static function root(): string
    {
        $r = trim((string) Setting::get('subtitle_root', ''));
        return $r !== '' ? rtrim(str_replace('\\', '/', $r), '/') : '/data/anime-subs';
    }

    public static function ffmpegBin(): string
    {
        $p = trim((string) Setting::get('ffmpeg_path', ''));
        return $p !== '' ? $p : 'ffmpeg';
    }

    /** 确保缓存根目录存在可写；失败时返回 false，由调用方给出明确错误 */
    private static function ensureRoot(): bool
    {
        $root = self::root();
        if (is_dir($root)) {
            return is_writable($root);
        }
        return @mkdir($root, 0755, true) || (is_dir($root) && is_writable($root));
    }

    /** 单集字幕目录 */
    private static function episodeDir(int $episodeId): string
    {
        return self::root() . '/' . $episodeId;
    }

    // ------------------------------------------------------------------
    // 探测：列出容器里的字幕流
    // ------------------------------------------------------------------

    /**
     * 列出容器内所有字幕流（含不可转的位图字幕，便于给出精确原因）。
     *
     * @return array<int,array{stream_index:int,sub_index:int,codec:string,language:string,title:string,convertible:bool}>
     */
    public static function listStreams(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $cmd = sprintf(
            'timeout 30 %s -v error -print_format json -show_streams -select_streams s %s 2>/dev/null',
            escapeshellcmd(MediaProbe::bin()),
            escapeshellarg($path)
        );
        $raw = @shell_exec($cmd);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $json = json_decode($raw, true);
        if (!is_array($json) || !isset($json['streams']) || !is_array($json['streams'])) {
            return [];
        }

        $out  = [];
        $subI = 0;
        foreach ($json['streams'] as $s) {
            if (!is_array($s)) {
                continue;
            }
            $codec = strtolower((string) ($s['codec_name'] ?? ''));
            $tags  = is_array($s['tags'] ?? null) ? $s['tags'] : [];
            $title = trim((string) ($tags['title'] ?? ''));
            $lang  = trim((string) ($tags['language'] ?? ''));

            $out[] = [
                'stream_index' => (int) ($s['index'] ?? -1),
                'sub_index'    => $subI,
                'codec'        => $codec,
                'language'     => self::normalizeLang($lang),
                'title'        => $title,
                'convertible'  => self::isConvertible($codec),
            ];
            $subI++;
        }

        return $out;
    }

    private static function isConvertible(string $codec): bool
    {
        if (in_array($codec, self::BITMAP_CODECS, true)) {
            return false;
        }
        // 认不出来的编码先乐观放行，交给 ffmpeg 用实际结果说话
        return true;
    }

    /** 把 ISO 639-2（chi/eng/jpn）和 639-1 统一成前端能用的简写 */
    private static function normalizeLang(string $lang): string
    {
        $lang = strtolower(trim($lang));
        $map  = [
            'chi' => 'zh', 'zho' => 'zh', 'chs' => 'zh', 'cht' => 'zh',
            'zh-cn' => 'zh', 'zh-tw' => 'zh', 'zh-hans' => 'zh', 'zh-hant' => 'zh',
            'eng' => 'en', 'jpn' => 'ja', 'kor' => 'ko',
        ];
        return $map[$lang] ?? $lang;
    }

    /** 展示名：优先流标题，其次语言，最后兜底序号 */
    private static function labelFor(array $s, int $ordinal): string
    {
        $title = trim((string) $s['title']);
        if ($title !== '') {
            return $title;
        }
        $lang = trim((string) $s['language']);
        if ($lang !== '') {
            return strtoupper($lang);
        }
        return '字幕 ' . ($ordinal + 1);
    }

    // ------------------------------------------------------------------
    // 抽取
    // ------------------------------------------------------------------

    /** 已在库里的字幕轨 */
    public static function tracks(int $episodeId): array
    {
        return Database::all(
            'SELECT `track_index`,`stream_index`,`codec`,`kind`,`language`,`label`,`file`,`cues`,`bytes`,`source_mtime`
             FROM `episode_subtitle` WHERE `episode_id` = :id ORDER BY `track_index`',
            ['id' => $episodeId]
        );
    }

    /** 缓存是否仍然有效：有轨，且每一条都出自当前这份源文件 */
    private static function cacheValid(array $tracks, int $mtime): bool
    {
        if (!$tracks) {
            return false;
        }
        foreach ($tracks as $t) {
            if ((int) $t['source_mtime'] !== $mtime) {
                return false;
            }
        }
        return true;
    }

    /**
     * 抽取单集字幕并落库。幂等：已有且源文件未变则直接跳过。
     *
     * @return array{ok:bool,tracks:array,skipped:bool,busy:bool,reason:?string,error:?string}
     */
    public static function extract(int $episodeId, bool $force = false): array
    {
        $ep = Database::one(
            'SELECT `id`,`file_path`,`sub_tracks`,`sub_extract_at`,`sub_extract_err`
             FROM `episode` WHERE `id` = :id LIMIT 1',
            ['id' => $episodeId]
        );
        if ($ep === null) {
            return self::result(false, [], 'episode_not_found', '剧集不存在');
        }

        $path = (string) $ep['file_path'];
        if ($path === '' || !is_file($path)) {
            self::markTried($episodeId, '源文件不存在或已被删除');
            return self::result(false, [], 'file_missing', '源文件不存在或已被删除');
        }

        $mtime = (int) @filemtime($path);
        $have  = self::tracks($episodeId);

        if (!$force && self::cacheValid($have, $mtime)) {
            return self::result(true, $have, 'cached', null, true);
        }

        // 无轨且最近已尝试过：区分「本来就没字幕」和「抽失败」两种情况
        if (!$force && !$have && $ep['sub_extract_at'] !== null) {
            $err  = $ep['sub_extract_err'] !== null ? (string) $ep['sub_extract_err'] : null;
            $age  = time() - strtotime((string) $ep['sub_extract_at']);
            if ($err === null) {
                return self::result(true, [], 'no_subtitle_stream', null, true);
            }
            if ($age < self::RETRY_COOLDOWN) {
                return self::result(false, [], 'cooling_down', $err, true);
            }
        }

        if (!self::ensureRoot()) {
            $msg = '字幕缓存目录不可写：' . self::root();
            self::markTried($episodeId, $msg);
            return self::result(false, [], 'root_unwritable', $msg);
        }

        // ---- 串行化：同一时刻只允许一路抽取 ----
        $lockErr = null;
        $lock = FileLock::open(self::root() . '/.extract.lock', $lockErr);
        if ($lock === null) {
            // 锁文件打不开是环境问题（多半是历史遗留的错误属主），必须落库 + 记日志，
            // 不能像以前那样只返回一个没人记录的 lock_unavailable —— 那会让 daemon
            // 每轮都报「失败 1」却查不到原因，最终表现为上传任务静默停摆。
            self::markTried($episodeId, (string) $lockErr);
            return self::result(false, [], 'lock_unavailable', $lockErr);
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return self::result(false, self::tracks($episodeId), 'busy', null, false, true);
        }

        try {
            // 拿到锁后重新查一次：可能刚被另一个请求抽完
            if (!$force && self::tracks($episodeId)) {
                return self::result(true, self::tracks($episodeId), 'cached', null, true);
            }

            $streams = self::listStreams($path);
            if (!$streams) {
                self::markTried($episodeId, null);
                return self::result(true, [], 'no_subtitle_stream');
            }

            $convertible = array_values(array_filter($streams, static fn(array $s): bool => (bool) $s['convertible']));
            $bitmap      = array_values(array_filter($streams, static fn(array $s): bool => !$s['convertible']));

            $dir = self::episodeDir($episodeId);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                $msg = '无法创建字幕目录：' . $dir;
                self::markTried($episodeId, $msg);
                return self::result(false, [], 'mkdir_failed', $msg);
            }

            $rows   = [];
            $errors = [];
            foreach ($convertible as $i => $s) {
                $file = sprintf('t%d.vtt', (int) $s['sub_index']);
                $dest = $dir . '/' . $file;

                $r = self::runFfmpeg($path, (int) $s['sub_index'], $dest);
                if (!$r['ok']) {
                    $errors[] = self::labelFor($s, $i) . ' 抽取失败';
                    @unlink($dest);
                    continue;
                }

                $cues = self::countCues($dest);
                if ($cues === 0) {
                    // 空轨（比如只有一条签名）不算失败，标记后跳过
                    @unlink($dest);
                    continue;
                }

                $rows[] = [
                    'episode_id'   => $episodeId,
                    'track_index'  => count($rows),
                    'stream_index' => (int) $s['stream_index'],
                    'codec'        => (string) $s['codec'],
                    'language'     => (string) $s['language'],
                    'label'        => self::labelFor($s, $i),
                    'file'         => $file,
                    'cues'         => $cues,
                    'bytes'        => (int) @filesize($dest),
                    'source_mtime' => $mtime,
                ];
            }

            // ---- 位图字幕（PGS / DVD）：抽 .sup 保底 + 排后台 OCR ----
            // 这一步是**必须在删源文件之前做**的：位图轨抽不出文本，源一删就彻底丢了。
            // 但 OCR 本身很慢（661 条约 15~30 分钟），绝不能在这里同步跑 —— 那会把
            // remux / 上传 / 删本地一起堵住。这里只做两件便宜事：
            //   1. `-c:s copy` 把位图轨导出成 .sup（约 2 秒）
            //   2. 把已有的 OCR 结果（上一轮跑完的）转成 VTT 登记
            // 起 OCR 进程与回收结果由 daemon 的独立 ocr 任务负责。
            $note = null;
            if ($bitmap) {
                $note = self::prepareBitmapTracks($episodeId, $path, $bitmap, $dir, $rows, $errors, $mtime);
            }

            if (!$rows && !$convertible) {
                // 只有位图轨、且 OCR 也没排上（环境不可用）→ 保持原来的明确报错
                $msg = $note ?? self::bitmapReason($streams);
                self::markTried($episodeId, $msg);
                return self::result(false, [], $bitmap ? 'bitmap_ocr' : 'bitmap_only', $msg);
            }

            // 清掉不再需要的旧文件。注意要**保留 .sup 与它的元数据/日志**：
            // 它们是位图字幕唯一的原始副本，也是后台 OCR 的输入。
            $keep = array_column($rows, 'file');
            foreach ((array) @scandir($dir) as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                if (str_ends_with($f, '.sup') || str_contains($f, '.sup.')
                    || str_ends_with($f, '.srt') || str_ends_with($f, '.json')
                ) {
                    continue;
                }
                if (!in_array($f, $keep, true)) {
                    @unlink($dir . '/' . $f);
                }
            }

            Database::run('DELETE FROM `episode_subtitle` WHERE `episode_id` = :id', ['id' => $episodeId]);
            foreach ($rows as $row) {
                Database::insert('episode_subtitle', $row);
            }

            $err = $errors ? implode('；', $errors) : null;
            self::markTried($episodeId, $err);

            return self::result($rows !== [], $rows, $rows ? 'extracted' : 'empty_tracks', $err);
        } catch (\Throwable $e) {
            $msg = '抽取异常：' . $e->getMessage();
            self::markTried($episodeId, mb_substr($msg, 0, 200));
            return self::result(false, [], 'exception', $msg);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** 单轨调 ffmpeg 转 WebVTT */
    private static function runFfmpeg(string $src, int $subIndex, string $dest): array
    {
        // -nostdin 防止 ffmpeg 抢走 fpm 的输入；只用 -map 0:s:N 取一条轨，
        // 其余流一律丢弃——这是「只解封装不转码」的关键。
        $cmd = sprintf(
            'timeout %d %s -nostdin -v error -i %s -map 0:s:%d -c:s webvtt -f webvtt %s -y 2>&1',
            self::FFMPEG_TIMEOUT,
            escapeshellcmd(self::ffmpegBin()),
            escapeshellarg($src),
            $subIndex,
            escapeshellarg($dest)
        );

        $lines = [];
        $rc    = 0;
        @exec($cmd, $lines, $rc);

        if ($rc !== 0 || !is_file($dest) || (int) @filesize($dest) === 0) {
            @unlink($dest);
            $tail = trim(implode(' ', array_slice($lines, -3)));
            return ['ok' => false, 'error' => $tail !== '' ? $tail : ('ffmpeg 退出码 ' . $rc)];
        }
        return ['ok' => true, 'error' => null];
    }

    private static function countCues(string $vttFile): int
    {
        $fh = @fopen($vttFile, 'r');
        if ($fh === false) {
            return 0;
        }
        $n = 0;
        while (($line = fgets($fh)) !== false) {
            if (str_contains($line, ' --> ')) {
                $n++;
            }
        }
        fclose($fh);
        return $n;
    }

    /**
     * 位图轨的准备工作：导出 .sup 保底 + 按设置排「渲染图片」或「OCR 成文本」。
     *
     * 只做便宜的事（导出约 2 秒/轨、读已有产物），**不起重活** ——
     * 渲染（约 1~2 分钟）与 OCR（约 8~15 分钟）都由 daemon 的 bitmap 任务异步跑，
     * 见 harvestBitmap()。同步做会把 remux / 上传 / 删本地一起堵住。
     *
     * @param array<int,array<string,mixed>> $bitmap
     * @param array<int,array<string,mixed>> $rows   出参：追加字幕行
     * @param array<int,string>              $errors 出参：追加错误
     * @return string|null 给用户看的说明（写入 sub_extract_err）
     */
    private static function prepareBitmapTracks(
        int $episodeId,
        string $path,
        array $bitmap,
        string $dir,
        array &$rows,
        array &$errors,
        int $mtime
    ): ?string {
        $picked = SubtitleOcr::pickTracks($bitmap);
        $policy = (string) Setting::get('subs_sup_keep', 'primary');
        $mode   = self::bitmapMode();

        $toExtract = match ($policy) {
            'all'   => $picked['all'],
            'none'  => [],
            default => $picked['primary'] !== null ? [$picked['primary']] : [],
        };

        $sups = [];
        foreach ($toExtract as $s) {
            $idx  = (int) $s['stream_index'];
            $lang = SubtitleOcr::langFor((string) ($s['title'] ?? ''), (string) ($s['language'] ?? ''));
            $sup  = sprintf('%s/s%d.%s.sup', $dir, $idx, $lang['pgsrip']);

            if (!is_file($sup)) {
                $r = SubtitleOcr::extractSup($path, $idx, $sup);
                if (!$r['ok']) {
                    $errors[] = ($s['title'] ?? ('轨' . $idx)) . ' 位图字幕导出失败';
                    continue;
                }
                // 元数据 sidecar：渲染/OCR 都是异步的，回收时源文件早没了，
                // 标签与语言只能在这里留下来。title 存**原样标题**，
                // 具体显示成「… · 原画」还是「… · OCR」由产物类型决定。
                @file_put_contents($sup . '.meta.json', json_encode([
                    'title'        => mb_substr((string) ($s['title'] ?? ''), 0, 160),
                    'cn'           => $lang['cn'],
                    'label'        => mb_substr((string) ($s['title'] ?? ''), 0, 160),
                    'language'     => $lang['lang'],
                    'codec'        => (string) $s['codec'],
                    'stream_index' => $idx,
                ], JSON_UNESCAPED_UNICODE));
                Log::write('subs', '位图字幕已导出为 .sup', [
                    'episode' => $episodeId, 'sup' => basename($sup), 'bytes' => $r['bytes'],
                ]);
            }
            $sups[$idx] = $sup;
        }

        if ($sups === []) {
            return '该片源只有位图字幕（PGS），且按设置未保留 .sup 原文件';
        }

        // 已有产物（上一轮跑完的）直接登记成可播字幕
        $imported = 0;
        foreach ($sups as $idx => $sup) {
            if (self::modeWantsImage($mode) && SubtitleImage::ready($dir, $idx)) {
                $imported += self::importImageResult($episodeId, $dir, $idx, $rows, $mtime) ? 1 : 0;
            }
            if (self::modeWantsOcr($mode) && is_file(SubtitleOcr::srtPathFor($sup))) {
                $imported += self::importOcrResult($episodeId, $sup, $dir, $rows, $mtime) ? 1 : 0;
            }
        }
        if ($imported > 0) {
            return null;   // 已经有可播字幕了，不必再提示
        }

        if ($mode === 'off') {
            return '该片源只有位图字幕（PGS），已保留 .sup 原文件；当前设置为「不转换」，'
                . '所以没有网页字幕。可在「设置 → 字幕」里改成渲染图片或 OCR。';
        }

        // 环境检查：不可用时明确说清楚，不要让任务永远挂着
        if (self::modeWantsImage($mode)) {
            $a = SubtitleImage::available();
            if (!$a['ok']) {
                return '该片源只有位图字幕（PGS），已保存 .sup 但无法渲染图片：' . $a['reason'];
            }
        }
        if (self::modeWantsOcr($mode)) {
            $a = SubtitleOcr::available();
            if (!$a['ok']) {
                return '该片源只有位图字幕（PGS），已保存 .sup 但无法 OCR：' . $a['reason'];
            }
        }

        $what = match ($mode) {
            'image' => '正在后台渲染字幕图片（约 1~2 分钟）',
            'ocr'   => '正在后台识别成文本（约十几分钟）',
            default => '正在后台渲染图片并识别文本（图片先就绪）',
        };
        return '该片源只有位图字幕（PGS），已保存原文件并' . $what
            . '，完成后会自动出现在字幕列表里。';
    }

    /** 位图字幕的处理模式 */
    public static function bitmapMode(): string
    {
        $m = strtolower(trim((string) Setting::get('subs_bitmap_mode', 'image')));
        return in_array($m, ['image', 'ocr', 'both', 'off'], true) ? $m : 'image';
    }

    public static function modeWantsImage(string $mode): bool
    {
        return in_array($mode, ['image', 'both'], true);
    }

    public static function modeWantsOcr(string $mode): bool
    {
        return in_array($mode, ['ocr', 'both'], true);
    }

    /**
     * 位图字幕轨的展示名。
     *
     * 兜底链要长一点：meta.json 是抽取时写的，而**已经存在的 meta 可能是旧版写的**
     * （只带 label、且 label 里已经拼了「· OCR」后缀）。只认 title 会让老条目的名字
     * 退化成「位图字幕」，只认 label 又会出现「… · OCR · 原画」这种叠后缀。
     */
    private static function supTitle(array $meta): string
    {
        foreach (['title', 'cn', 'label'] as $k) {
            $v = (string) ($meta[$k] ?? '');
            if ($v === '') {
                continue;
            }
            $v = trim((string) preg_replace('/\s*[·•]\s*(?:OCR|原画)\s*$/ui', '', $v));
            if ($v !== '') {
                return $v;
            }
        }
        return '位图字幕';
    }

    /** 读 sup 旁边的元数据 */
    private static function supMeta(string $sup): array
    {
        if (!is_file($sup . '.meta.json')) {
            return [];
        }
        return json_decode((string) @file_get_contents($sup . '.meta.json'), true) ?: [];
    }

    /**
     * 把渲染好的图片字幕（manifest.json + 图片）登记成一轨。
     *
     * @param array<int,array<string,mixed>> $rows 出参
     */
    private static function importImageResult(
        int $episodeId,
        string $dir,
        int $streamIndex,
        array &$rows,
        int $mtime
    ): bool {
        $man = SubtitleImage::manifest($dir, $streamIndex);
        if ($man === null || empty($man['cues'])) {
            return false;
        }

        $imgDir = SubtitleImage::dirFor($dir, $streamIndex);
        $bytes  = 0;
        foreach (glob($imgDir . '/*') ?: [] as $f) {
            $bytes += (int) @filesize($f);
        }

        // 元数据在 sup 旁边；sup 的文件名里带语言码
        $sup  = glob(sprintf('%s/s%d.*.sup', $dir, $streamIndex))[0] ?? '';
        $meta = $sup !== '' ? self::supMeta($sup) : [];
        $title = self::supTitle($meta);

        $rows[] = [
            'episode_id'   => $episodeId,
            'track_index'  => count($rows),
            'stream_index' => $streamIndex,
            'codec'        => (string) ($meta['codec'] ?? 'hdmv_pgs_subtitle') . '+img',
            'kind'         => 'image',
            'language'     => (string) ($meta['language'] ?? SubtitleOcr::langFromFilename($sup)),
            'label'        => mb_substr($title, 0, 150) . ' · 原画',
            'file'         => sprintf('img%d/manifest.json', $streamIndex),
            'cues'         => count($man['cues']),
            'bytes'        => $bytes,
            'source_mtime' => $mtime,
        ];

        Log::write('subs', '位图字幕图片已登记', [
            'episode' => $episodeId, 'track' => $streamIndex, 'cues' => count($man['cues']),
        ]);
        return true;
    }

    /**
     * 把一条 OCR 结果（.srt）转成 WebVTT 并登记成字幕行。
     *
     * @param array<int,array<string,mixed>> $rows 出参
     */
    private static function importOcrResult(int $episodeId, string $sup, string $dir, array &$rows, int $mtime): bool
    {
        $srt = SubtitleOcr::srtPathFor($sup);
        if (!is_file($srt) || (int) @filesize($srt) === 0) {
            return false;
        }

        $meta = self::supMeta($sup);
        $idx  = (int) ($meta['stream_index'] ?? 0);
        $file = sprintf('s%d.vtt', $idx);
        $dest = $dir . '/' . $file;

        $r = SubtitleOcr::srtToVtt($srt, $dest);
        if (!$r['ok']) {
            return false;
        }

        $title = self::supTitle($meta);

        $rows[] = [
            'episode_id'   => $episodeId,
            'track_index'  => count($rows),
            'stream_index' => $idx,
            'codec'        => (string) ($meta['codec'] ?? 'hdmv_pgs_subtitle') . '+ocr',
            'kind'         => 'ocr',
            'language'     => (string) ($meta['language'] ?? SubtitleOcr::langFromFilename($sup)),
            'label'        => mb_substr($title, 0, 150) . ' · OCR',
            'file'         => $file,
            'cues'         => (int) $r['cues'],
            'bytes'        => (int) @filesize($dest),
            'source_mtime' => $mtime,
        ];

        Log::write('subs', '位图字幕 OCR 完成并登记', [
            'episode' => $episodeId, 'file' => $file, 'cues' => (int) $r['cues'],
        ]);
        return true;
    }

    /**
     * 位图字幕的后台驱动：起进程 + 回收产物。daemon 的 bitmap 任务每轮调用。
     *
     * 每轮最多起 $spawn 个：2 核机器上渲染/OCR 都会吃满 CPU，
     * 一次塞太多会把 remux / 上传拖慢。
     *
     * @return array{spawned:int,imported:int,dirs:int}
     */
    public static function harvestBitmap(int $spawn = 1, bool $force = false): array
    {
        $out = ['spawned' => 0, 'imported' => 0, 'dirs' => 0];

        $mode = self::bitmapMode();
        if ($mode === 'off') {
            return $out;
        }
        $wantImg = self::modeWantsImage($mode) && SubtitleImage::available()['ok'];
        $wantOcr = self::modeWantsOcr($mode) && SubtitleOcr::available()['ok'];
        if (!$wantImg && !$wantOcr) {
            return $out;
        }

        foreach (glob(self::root() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $episodeId = (int) basename($dir);
            if ($episodeId <= 0) {
                continue;
            }
            $out['dirs']++;

            foreach (glob($dir . '/*.sup') ?: [] as $sup) {
                $idx  = (int) (self::supMeta($sup)['stream_index'] ?? 0);
                $rows = [];

                // ---- 图片叠加层 ----
                if ($wantImg) {
                    if (SubtitleImage::ready($dir, $idx)) {
                        if ($force || !self::kindRegistered($episodeId, 'image')) {
                            if (self::importImageResult($episodeId, $dir, $idx, $rows, 0)) {
                                self::registerRows($episodeId, $rows, 'image');
                                $out['imported']++;
                            }
                        }
                    } elseif ($out['spawned'] < $spawn
                        && !SubtitleImage::running($dir, $idx)
                        && !SubtitleImage::exhausted($dir, $idx)
                    ) {
                        $r = SubtitleImage::spawn($sup, $dir, $idx);
                        if ($r['spawned']) {
                            $out['spawned']++;
                        }
                    }
                }

                // ---- OCR 文本 ----
                if ($wantOcr && $out['spawned'] < $spawn) {
                    $srt = SubtitleOcr::srtPathFor($sup);
                    if (is_file($srt)) {
                        if ($force || !self::kindRegistered($episodeId, 'ocr')) {
                            $rows2 = [];
                            if (self::importOcrResult($episodeId, $sup, $dir, $rows2, 0)) {
                                self::registerRows($episodeId, $rows2, 'ocr');
                                $out['imported']++;
                            }
                        }
                    } elseif (!SubtitleOcr::running($sup) && !SubtitleOcr::exhausted($sup)) {
                        $r = SubtitleOcr::spawnOcr($sup, SubtitleOcr::langFromFilename($sup));
                        if ($r['spawned']) {
                            $out['spawned']++;
                        }
                    }
                }
            }

            self::refreshBitmapNote($episodeId, $dir);
        }

        return $out;
    }

    /** 该集是否已有某类产物的字幕行 */
    private static function kindRegistered(int $episodeId, string $kind): bool
    {
        return Database::val(
            'SELECT 1 FROM `episode_subtitle` WHERE `episode_id` = :e AND `kind` = :k LIMIT 1',
            ['e' => $episodeId, 'k' => $kind]
        ) !== null;
    }

    /** 全部替换某集的字幕行（按 kind 分治，不影响别类产物） */
    private static function registerRows(int $episodeId, array $rows, string $kind): void
    {
        // 只删自己这一类，别类产物（文本轨 / OCR / 图片层可以共存）要留着
        Database::run(
            'DELETE FROM `episode_subtitle` WHERE `episode_id` = :id AND `kind` = :k',
            ['id' => $episodeId, 'k' => $kind]
        );

        // 剩下这些行要重新编号（给新行腾出连续的位置）。
        // 注意 (episode_id, track_index) 是唯一键，直接 UPDATE 会撞上还没挪走的旧号：
        // 先把它们整体挪到一个高位区间，再落位 —— 否则「0→1」会和原本占着 1 的那行冲突。
        $rest = Database::all(
            'SELECT `id` FROM `episode_subtitle`
             WHERE `episode_id` = :id AND `kind` <> :k ORDER BY `track_index`',
            ['id' => $episodeId, 'k' => $kind]
        );
        foreach ($rest as $n => $e) {
            Database::run(
                'UPDATE `episode_subtitle` SET `track_index` = :t WHERE `id` = :id',
                ['t' => 1000 + (int) $n, 'id' => (int) $e['id']]
            );
        }
        foreach ($rest as $n => $e) {
            Database::run(
                'UPDATE `episode_subtitle` SET `track_index` = :t WHERE `id` = :id',
                ['t' => (int) $n, 'id' => (int) $e['id']]
            );
        }

        // 新行接在后面，编号统一在这里发 —— 不能沿用调用方预设的 track_index，
        // 否则会和保留下来的行撞唯一键
        $i = count($rest);
        foreach ($rows as $row) {
            $row['track_index'] = $i++;
            Database::insert('episode_subtitle', $row);
        }
        unset($i);
    }

    /**
     * 维护「位图字幕正在处理」这条提示文案。
     *
     * 必须是**双向**的：既要在产物就绪后清掉，也要在还有待处理项时补上。
     * 只做清空的话，用户会看到「没有任何字幕」却不知道为什么 —— 明明在渲染/识别中。
     */
    private static function refreshBitmapNote(int $episodeId, string $dir): void
    {
        $row = Database::one(
            'SELECT `sub_extract_err` FROM `episode` WHERE `id` = :id LIMIT 1',
            ['id' => $episodeId]
        );
        if ($row === null) {
            return;
        }

        $sups = glob($dir . '/*.sup') ?: [];
        if ($sups === []) {
            return;   // 这个目录跟位图字幕无关
        }

        $mode    = self::bitmapMode();
        $pending = 0;
        foreach ($sups as $sup) {
            $idx = (int) (self::supMeta($sup)['stream_index'] ?? 0);
            if (self::modeWantsImage($mode)
                && !SubtitleImage::ready($dir, $idx)
                && !SubtitleImage::exhausted($dir, $idx)
            ) {
                $pending++;
            }
            if (self::modeWantsOcr($mode)
                && !is_file(SubtitleOcr::srtPathFor($sup))
                && !SubtitleOcr::exhausted($sup)
            ) {
                $pending++;
            }
        }

        $has = (int) Database::val(
            'SELECT COUNT(*) FROM `episode_subtitle` WHERE `episode_id` = :id',
            ['id' => $episodeId]
        );

        if ($pending > 0 && $has === 0) {
            $note = sprintf(
                '该片源只有位图字幕（PGS），正在后台处理（%d 项），完成后会自动出现在字幕列表里',
                $pending
            );
        } elseif ($pending === 0 && $has === 0) {
            $note = '位图字幕处理完但没有产出可用字幕（可能是纯特效/卡拉OK轨，或转换环境不可用）';
        } else {
            $note = null;
        }

        Database::run(
            'UPDATE `episode` SET `sub_extract_err` = :e WHERE `id` = :id',
            ['e' => $note, 'id' => $episodeId]
        );
    }

    private static function bitmapReason(array $streams): string
    {
        $names = [];
        foreach ($streams as $s) {
            $c = (string) $s['codec'];
            $names[$c] = in_array($c, self::BITMAP_CODECS, true) ? '位图字幕' : '未知编码';
        }
        $parts = [];
        foreach ($names as $codec => $kind) {
            $parts[] = strtoupper($codec) . '（' . $kind . '）';
        }
        return '该片源只有 ' . implode('、', $parts)
            . '，是图像形式而非文本，无法转成网页字幕。建议换一个字幕组版本。';
    }

    private static function markTried(int $episodeId, ?string $err): void
    {
        Database::update('episode', [
            'sub_extract_at'  => date('Y-m-d H:i:s'),
            'sub_extract_err' => $err,
        ], '`id` = :id', ['id' => $episodeId]);
    }

    private static function result(
        bool $ok,
        array $tracks,
        ?string $reason = null,
        ?string $error = null,
        bool $skipped = false,
        bool $busy = false
    ): array {
        return [
            'ok'      => $ok,
            'tracks'  => $tracks,
            'reason'  => $reason,
            'error'   => $error,
            'skipped' => $skipped,
            'busy'    => $busy,
        ];
    }

    // ------------------------------------------------------------------
    // 取文件（供 HTTP 分发）
    // ------------------------------------------------------------------

    /**
     * 定位某条轨的 VTT 文件。路径必须在缓存根目录内，防目录穿越。
     */
    public static function vttPath(int $episodeId, int $trackIndex): ?string
    {
        $row = Database::one(
            'SELECT `file` FROM `episode_subtitle` WHERE `episode_id` = :e AND `track_index` = :t LIMIT 1',
            ['e' => $episodeId, 't' => $trackIndex]
        );
        if ($row === null) {
            return null;
        }

        $file = basename((string) $row['file']);          // 去掉任何路径成分
        $full = self::episodeDir($episodeId) . '/' . $file;

        $real = realpath($full);
        $base = realpath(self::root());
        if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return is_file($real) ? $real : null;
    }

    /**
     * 定位图片字幕轨里的单个文件（manifest.json 或某张图片）。
     *
     * 与 vttPath 一样必须防目录穿越：文件名只允许数字编号 + 白名单后缀，
     * 且最终路径必须落在缓存根目录内。
     */
    public static function assetPath(int $episodeId, int $trackIndex, string $name): ?string
    {
        $row = Database::one(
            'SELECT `file` FROM `episode_subtitle`
             WHERE `episode_id` = :e AND `track_index` = :t AND `kind` = \'image\' LIMIT 1',
            ['e' => $episodeId, 't' => $trackIndex]
        );
        if ($row === null) {
            return null;
        }

        // 该轨的 file 形如 `img3/manifest.json`，取它的目录作为产物目录
        $subDir = dirname(str_replace('\\', '/', (string) $row['file']));
        if ($subDir === '' || $subDir === '.' || $subDir === '/') {
            return null;
        }

        if ($name !== 'manifest.json' && !SubtitleImage::allowedImageName($name)) {
            return null;
        }

        $full = self::episodeDir($episodeId) . '/' . basename($subDir) . '/' . $name;
        $real = realpath($full);
        $base = realpath(self::root());
        if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return is_file($real) ? $real : null;
    }

    /** 待抽取的剧集：已探测出有字幕轨、但还没抽过 */
    public static function pending(int $limit = 5): array
    {
        $limit = max(1, min(200, $limit));
        $rows  = Database::all(
            'SELECT `id`,`file_path`,`sub_tracks`
             FROM `episode`
             WHERE `sub_tracks` > 0
               AND `sub_extract_at` IS NULL
               AND `file_path` <> \'\'
             ORDER BY `id` DESC
             LIMIT ' . ($limit * 4)
        );

        // 「本地文件已被删除」不能用 `s3_purged_at IS NOT NULL` 判断 —— 那只是
        // 「某个时刻删过一次」的历史记录，不代表现在没有文件（例如为了重抽字幕把源
        // 重新下回来时，时间戳还在）。按文件系统实际情况过滤才准确。
        $out = [];
        foreach ($rows as $r) {
            if (is_file((string) $r['file_path'])) {
                $out[] = $r;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** 删掉某集的缓存文件与记录 */
    public static function purge(int $episodeId): void
    {
        $dir = self::episodeDir($episodeId);
        foreach ((array) @scandir($dir) as $f) {
            if ($f !== '.' && $f !== '..') {
                @unlink($dir . '/' . $f);
            }
        }
        @rmdir($dir);
        Database::run('DELETE FROM `episode_subtitle` WHERE `episode_id` = :id', ['id' => $episodeId]);
    }
}
