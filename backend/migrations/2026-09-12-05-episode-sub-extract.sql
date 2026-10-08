-- 字幕抽取状态：记录抽取尝试的时间与失败原因。
--
-- 单独记一份的原因：「没有字幕」和「抽失败了」在 episode_subtitle 里都是零行，
-- 分不清就会在每次播放时反复重试同一个抽不出来的文件。
-- 位图字幕（PGS/DVD/DVB）转不了 WebVTT，必须记下原因才能告诉用户为什么没字幕。
ALTER TABLE `episode`
  ADD COLUMN `sub_extract_at`  DATETIME     DEFAULT NULL COMMENT '最近一次尝试抽取字幕的时间',
  ADD COLUMN `sub_extract_err` VARCHAR(255) DEFAULT NULL COMMENT '字幕抽取失败原因，NULL 表示成功或无字幕';
