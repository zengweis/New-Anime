<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Log;
use App\Core\Setting;

/**
 * 位图字幕（HDMV PGS / DVD）→ 文本字幕。
 *
 * 为什么需要它：
 *   MKV 里的 PGS 字幕是**一帧一张位图**，WebVTT 表达不了，所以以前只能对用户说
 *   「换一个有文本字幕的片源」。但这条路上有个更糟的后果 —— 我们的「上传后删本地」
 *   会把源文件删掉，而那些位图字幕就**永久消失**了。所以现在改成：
 *
 *     1. 抽源文件时就把位图轨原样导出为 `.sup`（`-c:s copy`，2 秒，不转码）
 *     2. 由独立任务在**后台异步** OCR 成 WebVTT，识别完再把字幕行登记进库
 *
 * 为什么必须异步：
 *   一次 OCR 要 5~10 分钟（逐帧 tesseract）。常驻进程的主循环是串行的 ——
 *   同步做会把 remux / 上传 / 删本地 一起堵住，新下载的集也排不进来。
 *   所以这里只负责「起一个脱离父进程的 OCR 进程」和「回收已完成的结果」，
 *   真正的识别永远不在主循环里等。
 *
 * 为什么离线不自己写 PGS 解析：
 *   PGS 的 ODS 段是 RLE 压缩的图像，自己解要重写一套解码器。`pgsrip`
 *   （PyPI，内含 trakit + pytesseract）已经做完了这件事，且只依赖 tesseract，
 *   装在一个独立 venv 里即可，不污染主程序。
 */
final class SubtitleOcr
{
    /** 单轨 OCR 的最长允许时间（秒）。PGS 逐帧识别慢，给足；超时视为失败。 */
    public const TIMEOUT = 2700;

    /** 认为「进程已死、标记可清理」的阈值（秒） */
    private const STALE_MARKER = 3600;

    /** OCR 失败后是否值得重试（避免每轮重复起进程） */
    private const MAX_ATTEMPTS = 2;

    /** pgsrip 可执行文件（默认装在独立 venv 里，可用设置覆盖） */
    public static function bin(): string
    {
        $p = trim((string) Setting::get('subs_ocr_bin', ''));
        return $p !== '' ? $p : '/opt/anime-new/venv-ocr/bin/pgsrip';
    }

    private static function ffmpegBin(): string
    {
        return RemuxService::ffmpegBin();
    }

    private static function tessdata(): string
    {
        $p = trim((string) Setting::get('subs_tessdata', ''));
        if ($p !== '' && is_dir($p)) {
            return $p;
        }
        // apt 装的 tesseract 把语言包放这里；找不到就不设，让 tesseract 用自己的默认路径
        foreach (['/usr/share/tesseract-ocr/5/tessdata', '/usr/share/tesseract-ocr/4.00/tessdata', '/usr/share/tessdata'] as $d) {
            if (is_dir($d)) {
                return $d;
            }
        }
        return '';
    }

    /**
     * OCR 环境是否可用。不可用时**不要**把任务标成待识别 —— 否则会永远挂着。
     *
     * @return array{ok:bool,reason:string,bin:string,tesseract:string}
     */
    public static function available(): array
    {
        $bin = self::bin();
        $tess = trim((string) (shell_exec('command -v tesseract 2>/dev/null') ?? ''));
        $ok   = $bin !== '' && is_file($bin) && is_executable($bin) && $tess !== '';

        $reason = '';
        if (!is_file($bin) || !is_executable($bin)) {
            $reason = '未找到 pgsrip：' . $bin;
        } elseif ($tess === '') {
            $reason = '未找到 tesseract（apt install tesseract-ocr tesseract-ocr-chi-sim tesseract-ocr-chi-tra）';
        }

        return ['ok' => $ok, 'reason' => $reason, 'bin' => $bin, 'tesseract' => $tess];
    }

    /**
     * 把某条位图轨原样导出为 `.sup`。
     *
     * 文件名**必须带上语言后缀**（如 `80-s3.zh-Hans.sup`）：pgsrip 是从文件名
     * 解析语言的（`Sup.__init__` 用 MediaPath.language），没有语言码的 `.sup`
     * 会被它直接过滤掉，报 "no track for the selected languages (available: none)"。
     */
    public static function extractSup(string $src, int $streamIndex, string $dest): array
    {
        $cmd = sprintf(
            'timeout %d %s -nostdin -v error -i %s -map 0:%d -c:s copy -f sup %s -y 2>&1',
            300,
            escapeshellcmd(self::ffmpegBin()),
            escapeshellarg($src),
            $streamIndex,
            escapeshellarg($dest)
        );

        $lines = [];
        $rc    = 0;
        @exec($cmd, $lines, $rc);

        if ($rc !== 0 || !is_file($dest) || (int) @filesize($dest) === 0) {
            @unlink($dest);
            $tail = trim(implode(' ', array_slice($lines, -3)));
            return ['ok' => false, 'bytes' => 0, 'error' => $tail !== '' ? $tail : ('ffmpeg 退出码 ' . $rc)];
        }
        return ['ok' => true, 'bytes' => (int) filesize($dest), 'error' => null];
    }

    /**
     * 起一个**脱离父进程**的 OCR 进程。
     *
     * 用 setsid + `&` 让它不随调用方结束而退出；完成后自己删掉「运行中」标记。
     * 调用方（常驻进程）立刻返回，不被阻塞。
     */
    public static function spawnOcr(string $supFile, string $lang): array
    {
        $env  = self::tessdata();
        $out  = self::srtPathFor($supFile);
        $log  = $supFile . '.ocr.log';
        $mark = $supFile . '.running';

        if (is_file($out)) {
            return ['ok' => true, 'spawned' => false, 'error' => null];
        }

        @touch($mark);
        $inner = sprintf(
            'export TESSDATA_PREFIX=%s; '
            . 'timeout %d %s rip %s -l %s --no-tessdata-download -f -w 2 > %s 2>&1; '
            . 'rm -f %s',
            escapeshellarg($env),
            self::TIMEOUT,
            escapeshellcmd(self::bin()),
            escapeshellarg($supFile),
            escapeshellarg($lang),
            escapeshellarg($log),
            escapeshellarg($mark)
        );

        $cmd = 'setsid bash -c ' . escapeshellarg($inner) . ' > /dev/null 2>&1 < /dev/null &';
        @exec($cmd);

        // 起完立刻确认进程真的在了（否则会一轮轮空转）
        usleep(400000);
        if (!is_file($mark)) {
            return ['ok' => false, 'spawned' => false, 'error' => 'OCR 进程未能启动'];
        }

        Log::write('subs', '位图字幕 OCR 已转入后台', ['sup' => basename($supFile), 'lang' => $lang]);
        return ['ok' => true, 'spawned' => true, 'error' => null];
    }

    /** sup 对应的 srt 路径（pgsrip 输出同名 .srt） */
    public static function srtPathFor(string $supFile): string
    {
        return preg_replace('/\.sup$/', '.srt', $supFile) ?? ($supFile . '.srt');
    }

    /** 是否有 OCR 正在进行（未完成 + 标记新鲜） */
    public static function running(string $supFile): bool
    {
        $mark = $supFile . '.running';
        if (!is_file($mark)) {
            return false;
        }
        $age = time() - (int) @filemtime($mark);
        if ($age > self::STALE_MARKER) {
            @unlink($mark);   // 进程被杀（重启/超时）留下的陈标记
            return false;
        }
        return true;
    }

    /**
     * OCR 是否已失败到底（避免无限重试）。
     *
     * 判定依据：有日志、没有 srt、没有 running 标记 —— 说明进程跑完但没产出。
     */
    public static function exhausted(string $supFile): bool
    {
        $log = $supFile . '.ocr.log';
        if (!is_file($log) || is_file(self::srtPathFor($supFile)) || self::running($supFile)) {
            return false;
        }
        // 记录尝试次数
        $cnt = (int) @file_get_contents($supFile . '.attempts');
        if ($cnt >= self::MAX_ATTEMPTS) {
            return true;
        }
        @file_put_contents($supFile . '.attempts', (string) ($cnt + 1));
        return false;
    }

    /**
     * SRT → WebVTT。
     *
     * 不能只是换个时间戳分隔符就完事 —— PGS OCR 的原始输出有两类**必须清掉**的噪声，
     * 否则字幕会完全没法看（实测过）：
     *
     *   1. **汉字之间被插空格**：tesseract 把每个汉字当成独立词，
     *      输出「好 像 有 人 倒 下 了」。中文之间的空格要去掉（中英之间保留）。
     *
     *   2. **同一条字幕被切成几十条**：PGS 是逐帧显示的（淡入/逐字），每个中间帧都是
     *      一次独立的显示事件，于是「不存在解不开的迹」会被切成 10+ 条、每条只有 40ms。
     *      这类碎片必须按「时间相邻 + 文本高度相似」合并回一条。
     */
    public static function srtToVtt(string $srt, string $dest): array
    {
        $raw = @file_get_contents($srt);
        if ($raw === false || trim($raw) === '') {
            return ['ok' => false, 'cues' => 0, 'error' => 'SRT 为空'];
        }

        $cues = self::cleanCues(self::parseSrt($raw));
        if (!$cues) {
            return ['ok' => false, 'cues' => 0, 'error' => '识别结果清洗后没有可用字幕'];
        }

        $vtt  = "WEBVTT\n\n";
        $i    = 0;
        foreach ($cues as $c) {
            $i++;
            $vtt .= $i . "\n"
                . self::vttTime($c['start']) . ' --> ' . self::vttTime($c['end']) . "\n"
                . $c['text'] . "\n\n";
        }

        if (@file_put_contents($dest, $vtt) === false) {
            return ['ok' => false, 'cues' => 0, 'error' => '写入 VTT 失败：' . $dest];
        }
        return ['ok' => true, 'cues' => $i, 'error' => null];
    }

    /** 解析 SRT 成 [{start,end,text}]（时间为秒） */
    private static function parseSrt(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $out = [];
        foreach (preg_split('/\n{2,}/', trim($raw)) ?: [] as $block) {
            $lines = array_values(array_filter(explode("\n", $block), static fn($l) => trim($l) !== ''));
            if (count($lines) < 2) {
                continue;
            }
            $tIdx = null;
            foreach ($lines as $k => $l) {
                if (str_contains($l, '-->')) {
                    $tIdx = $k;
                    break;
                }
            }
            if ($tIdx === null) {
                continue;
            }
            if (!preg_match('/(\d+):(\d+):(\d+)[,.](\d+)\s*-->\s*(\d+):(\d+):(\d+)[,.](\d+)/', $lines[$tIdx], $m)) {
                continue;
            }
            $start = ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3] + ((int) $m[4]) / 1000;
            $end   = ((int) $m[5]) * 3600 + ((int) $m[6]) * 60 + ((int) $m[7]) + ((int) $m[8]) / 1000;
            $text  = trim(implode("\n", array_slice($lines, $tIdx + 1)));
            if ($text !== '') {
                $out[] = ['start' => $start, 'end' => $end, 'text' => $text];
            }
        }
        return $out;
    }

    /**
     * 清洗 + 合并碎片。这是 OCR 能否可用的关键一步，规则都来自真实样本的观察。
     *
     * @param array<int,array{start:float,end:float,text:string}> $cues
     * @return array<int,array{start:float,end:float,text:string}>
     */
    private static function cleanCues(array $cues): array
    {
        $cjk = '\x{3000}-\x{303F}\x{4E00}-\x{9FFF}\x{FF00}-\x{FFEF}';

        // ---- 1. 文本规范化 ----
        foreach ($cues as &$c) {
            $t = str_replace(["\u{3000}", "\u{00A0}"], ' ', $c['text']);
            $t = str_replace("\n", ' ', $t);
            // 汉字之间的空格去掉（中英/中数之间保留，读起来才正常）
            $t = preg_replace('/(?<=[' . $cjk . '])\s+(?=[' . $cjk . '])/u', '', (string) $t) ?? (string) $t;
            $t = preg_replace('/\s{2,}/', ' ', (string) $t) ?? (string) $t;
            // 去掉行首行尾的孤立标点：PGS 换页时会把上一帧的标点留在开头，
            // 于是出现「、以及从卫生间里走出来的…」这种。只削标点，不动正文。
            $t = preg_replace('/^[\s、，。．！？：；…·,\.!\?:;\[\]（）()「」『』\-—–]+/u', '', (string) $t) ?? (string) $t;
            $t = preg_replace('/[\s、，。．！？：；…·,\.!\?:;\[\]（）()「」『』\-—–]+$/u', '', (string) $t) ?? (string) $t;
            $c['text'] = trim((string) $t);
        }
        unset($c);

        // ---- 2. 合并渐进显示的碎片 ----
        $merged = [];
        foreach ($cues as $c) {
            if ($c['text'] === '') {
                continue;
            }
            $n = count($merged);
            if ($n > 0) {
                $last = $merged[$n - 1];
                // 间隙很小（同一句的连续帧）且文本高度相似 → 并成一条
                if ($c['start'] - $last['end'] <= 1.5 && self::looksSame($last['text'], $c['text'])) {
                    $merged[$n - 1]['end'] = max($last['end'], $c['end']);
                    if (mb_strlen($c['text']) > mb_strlen($last['text'])) {
                        $merged[$n - 1]['text'] = $c['text'];   // 保留更完整的那一版
                    }
                    continue;
                }
            }
            $merged[] = $c;
        }

        // ---- 3. 丢掉噪声：过短、纯符号、单字、标点堆叠 ----
        $out = [];
        foreach ($merged as $c) {
            if ($c['end'] - $c['start'] < 0.2) {
                continue;   // 合并后仍只有几百毫秒 → 多半是特效帧
            }
            $plain = (string) preg_replace('/[^\p{Han}\p{L}\p{N}]/u', '', $c['text']);
            if ($plain === '' || mb_strlen($plain) < 2) {
                continue;   // 只剩标点或单个字符
            }
            // 连续两个以上标点 = 把画面上的装饰/台标识别成了字幕（实测出现过「。.。。」）
            if (preg_match('/[。．，、！？：；…·.,!?:;]{2,}/u', $c['text']) === 1) {
                continue;
            }
            $out[] = $c;
        }
        return $out;
    }

    /** 两条文本是否「同一句」（PGS 渐进显示的前后帧） */
    private static function looksSame(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        // 一版是另一版的子串：逐字显示时后一帧更全
        if (str_contains($b, $a) || str_contains($a, $b)) {
            return true;
        }
        // 字符集合重合度（OCR 逐帧会有个别字识别不一致）
        $sa = array_unique(preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $sb = array_unique(preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $min = min(count($sa), count($sb));
        if ($min === 0) {
            return false;
        }
        return count(array_intersect($sa, $sb)) / $min >= 0.7;
    }

    /** 秒 → WebVTT 时间戳 */
    private static function vttTime(float $sec): string
    {
        $sec = max(0.0, $sec);
        $h   = (int) floor($sec / 3600);
        $m   = (int) floor(($sec % 3600) / 60);
        $s   = (int) floor($sec % 60);
        $ms  = (int) round(($sec - floor($sec)) * 1000);
        if ($ms === 1000) {
            $ms = 0;
            $s++;
        }
        return sprintf('%02d:%02d:%02d.%03d', $h, $m, $s, $ms);
    }

    /**
     * 从轨道标题/语言标签推断 OCR 语言。
     *
     * @return array{pgsrip:string,tess:string,lang:string,cn:string}
     */
    public static function langFor(string $title, string $language): array
    {
        $t = mb_strtolower($title . ' ' . $language);

        $hasHant = preg_match('/繁|cht|zh-hant|hant|big5/u', $t) === 1;
        $hasHans = preg_match('/简|簡|chs|zh-hans|hans|gb/u', $t) === 1;
        $hasJp   = preg_match('/日|jp|jpn|japanese/u', $t) === 1;

        // 日文和中文混在一个标题里（「简体中文&日语」）按中文处理：中文观众的诉求是中文
        if ($hasHans) {
            return ['pgsrip' => 'zh-Hans', 'tess' => 'chi_sim', 'lang' => 'zh-Hans', 'cn' => '简体中文'];
        }
        if ($hasHant) {
            return ['pgsrip' => 'zh-Hant', 'tess' => 'chi_tra', 'lang' => 'zh-Hant', 'cn' => '繁體中文'];
        }
        if ($hasJp) {
            return ['pgsrip' => 'ja', 'tess' => 'jpn', 'lang' => 'ja', 'cn' => '日本語'];
        }
        return ['pgsrip' => 'zh-Hans', 'tess' => 'chi_sim', 'lang' => 'zh-Hans', 'cn' => '简体中文'];
    }

    /**
     * 从一组位图轨里挑出**最值得 OCR 的一条，以及其余值得保留的**。
     *
     * 优先级：单语简体 > 简体 > 单语繁体 > 繁体 > 最早的一条。
     * 「单语」优先是因为双语轨是一行中文一行日文，OCR 出来会混在一起，读起来更糟。
     *
     * @param array<int,array<string,mixed>> $bitmap listStreams() 里 convertible=false 的那些
     * @return array{primary:?array<string,mixed>,all:array<int,array<string,mixed>>}
     */
    public static function pickTracks(array $bitmap): array
    {
        if (!$bitmap) {
            return ['primary' => null, 'all' => []];
        }

        $score = static function (array $s): int {
            $t = mb_strtolower((string) ($s['title'] ?? '') . ' ' . ((string) ($s['language'] ?? '')));
            $single = preg_match('/单语|單語|单語|single/u', $t) === 1;
            $hans   = preg_match('/简|簡|chs|hans|gb/u', $t) === 1;
            $hant   = preg_match('/繁|cht|hant|big5/u', $t) === 1;

            if ($hans && $single) {
                return 100;
            }
            if ($hans) {
                return 80;
            }
            if ($hant && $single) {
                return 70;
            }
            if ($hant) {
                return 60;
            }
            return 10;
        };

        $ranked = $bitmap;
        usort($ranked, static fn($a, $b) => $score($b) <=> $score($a));
        return ['primary' => $ranked[0], 'all' => $ranked];
    }

    /**
     * 扫描某个剧集目录里待处理 / 已完成的 sup。
     *
     * @return array{pending:array<int,string>,finished:array<int,array{sup:string,srt:string}>}
     */
    public static function scan(string $dir): array
    {
        $pending  = [];
        $finished = [];
        foreach (glob($dir . '/*.sup') ?: [] as $sup) {
            $srt = self::srtPathFor($sup);
            if (is_file($srt) && (int) @filesize($srt) > 0) {
                $finished[] = ['sup' => $sup, 'srt' => $srt];
                continue;
            }
            if (self::running($sup) || self::exhausted($sup)) {
                continue;
            }
            $pending[] = $sup;
        }
        return ['pending' => $pending, 'finished' => $finished];
    }

    /** 从 `xx.zh-Hans.sup` 这类文件名里取回语言码 */
    public static function langFromFilename(string $supFile): string
    {
        if (preg_match('/\.([a-z]{2}(?:-[A-Za-z]+)?)\.sup$/', $supFile, $m)) {
            return $m[1];
        }
        return 'zh-Hans';
    }
}
