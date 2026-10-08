-- 修复被清空的 fetch_interval_min（抓取间隔）
--
-- 背景：这个键此前**没有默认值种子**。设置页是「整表回传」，用户点一次保存就会把它
-- 以空字符串写进库；而 `Setting::get()` 对「键存在但值为空」返回的是 `''` 而不是默认值，
-- 下游 `(int) '' === 0`，`max(60, 0*60)` 兜成 60 秒 —— 于是「每 30 分钟抓一次」
-- 实际变成「每 1 分钟抓一次」，表现是设置页空着、daemon 日志却写「抓取 60s」。
--
-- 这里只在确实为空时补 30，不覆盖用户已设的值。
-- 同时补进 schema.sql，保证全新库直接带上默认值。
-- 代码侧也加了 Setting::int()（空值按默认处理），从源头避免同类问题。

INSERT INTO `setting` (`k`,`v`) VALUES ('fetch_interval_min', '30')
ON DUPLICATE KEY UPDATE `v` = IF(`v` = '' OR `v` IS NULL, '30', `v`);
