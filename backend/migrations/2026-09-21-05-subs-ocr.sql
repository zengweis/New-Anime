-- 位图字幕（PGS/DVD）OCR 相关设置
--
-- 背景：MKV 里的 PGS 是「一帧一张位图」，WebVTT 表达不了。以前只能对用户说
-- 「换个有文本字幕的片源」，但更糟的是：我们的「上传后删本地」会把源文件删掉，
-- 那些位图字幕就永久消失了。现在改成「抽 .sup 保底 + 后台 OCR 成文本字幕」。
--
--   subs_ocr_bitmap  是否启用位图字幕 OCR（1 开）。关掉则只保存 .sup，不做识别。
--   subs_sup_keep    .sup 保留策略：
--                      primary（默认）只留要识别的那条，约 17MB/集，磁盘可预期
--                      all  全部保留（5 轨约 85MB/集，保住所有语言变体）
--                      none 识别完不留，最省磁盘但无法重识别
--   subs_ocr_bin     pgsrip 可执行路径（独立 venv，不污染主程序依赖）
--   subs_tessdata    tesseract 语言包目录，留空则自动探测

INSERT INTO `setting` (`k`,`v`) VALUES
  ('subs_ocr_bitmap', '1'),
  ('subs_sup_keep',   'primary'),
  ('subs_ocr_bin',    '/opt/anime-new/venv-ocr/bin/pgsrip'),
  ('subs_tessdata',   '')
ON DUPLICATE KEY UPDATE `k` = `k`;
