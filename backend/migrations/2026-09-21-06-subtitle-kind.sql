-- 字幕轨类型：text（WebVTT 文本） / image（位图字幕渲成的透明图片叠加层）
--
-- 背景：PGS/DVD 这类位图字幕是图像，做文本只能 OCR（每轨约 8 分钟 CPU，且片头曲、
-- 特效、画面标牌会识别出错字）。另一个选择是把 PGS 按自带的时机与位置渲成透明图片，
-- 由前端叠加在视频上 —— 100% 保真、CPU 只花一分多钟。
-- 两种产物形态不同（.vtt vs manifest.json + 一堆图片），所以必须区分类型，
-- 否则前端不知道拿到的该塞给 <track> 还是叠加层。

ALTER TABLE `episode_subtitle`
  ADD COLUMN `kind` VARCHAR(8) NOT NULL DEFAULT 'text'
    COMMENT 'text=WebVTT 文本 / image=位图字幕图片叠加层' AFTER `codec`;

-- 位图字幕渲染模式
--   image（默认）只渲图片叠加层：保真、快
--   ocr          只做 OCR 文本：可选文本、可搜索，但慢且特效处会出错字
--   both         两者都做（占 CPU 和磁盘都翻倍）
--   off          只保存 .sup 原文件，不做任何转换
INSERT INTO `setting` (`k`,`v`) VALUES
  ('subs_bitmap_mode',   'image'),
  ('subs_img_format',    'webp'),
  ('subs_img_quality',   '90'),
  ('subs_img_threshold', '0.02'),
  ('subs_py_bin',        '')
ON DUPLICATE KEY UPDATE `k` = `k`;
