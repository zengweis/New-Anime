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

    private static function modeWantsImage(string $mode): bool
    {
        return in_array($mode, ['image', 'both'], true);
    }

    private static function modeWantsOcr(string $mode): bool
    {
        return in_array($mode, ['ocr', 'both'], true);
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
        $title = (string) ($meta['title'] ?? '');
        if ($title === '') {
            $title = (string) ($meta['cn'] ?? '位图字幕');
        }

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

        $title = (string) ($meta['title'] ?? '');
        if ($title === '') {
            $title = (string) ($meta['cn'] ?? '位图字幕');
        }

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
        // 保留其它 kind 的行：文本轨、OCR 文本、图片叠加层可以共存
        $existing = Database::all(
            'SELECT * FROM `episode_subtitle` WHERE `episode_id` = :id AND `kind` <> :k',
            ['id' => $episodeId, 'k' => $kind]
        );
        Database::run(
            'DELETE FROM `episode_subtitle` WHERE `episode_id` = :id AND `kind` = :k',
            ['id' => $episodeId, 'k' => $kind]
        );

        $i = 0;
        foreach ($existing as $e) {
            Database::insert('episode_subtitle', [
                'episode_id'   => $episodeId,
                'track_index'  => $i++,
                'stream_index' => (int) $e['stream_index'],
                'codec'        => (string) $e['codec'],
                'kind'         => (string) $e['kind'],
                'language'     => (string) $e['language'],
                'label'        => (string) $e['label'],
                'file'         => (string) $e['file'],
                'cues'         => (int) $e['cues'],
                'bytes'        => (int) $e['bytes'],
                'source_mtime' => (int) $e['source_mtime'],
            ]);
        }
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

