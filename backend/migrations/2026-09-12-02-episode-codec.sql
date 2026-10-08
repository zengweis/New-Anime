-- 片源编码探测结果（由 bin/probe_episodes.php 用 ffprobe 写入）
-- 用途：播放前决定走「原生直通」还是「前端解封装」，并给出精确的降级原因，
--       不再靠文件后缀猜「能不能播」。
-- 已有库升级用；新库由 schema.sql 直接建出这些列。
ALTER TABLE `episode`
  ADD COLUMN `vcodec`      VARCHAR(20)  DEFAULT NULL COMMENT '视频编码 hevc/h264/av1/vp9' AFTER `resolution`,
  ADD COLUMN `acodec`      VARCHAR(20)  DEFAULT NULL COMMENT '音频编码 aac/flac/opus/ac3/eac3' AFTER `vcodec`,
  ADD COLUMN `pix_fmt`     VARCHAR(24)  DEFAULT NULL COMMENT '像素格式，yuv420p10le 即 10bit' AFTER `acodec`,
  ADD COLUMN `sub_tracks`  TINYINT      NOT NULL DEFAULT 0 COMMENT '内嵌字幕轨数量' AFTER `pix_fmt`,
  ADD COLUMN `probe_at`    DATETIME     DEFAULT NULL COMMENT '最近一次探测时间' AFTER `sub_tracks`,
  ADD COLUMN `probe_error` VARCHAR(255) DEFAULT NULL COMMENT '探测失败原因' AFTER `probe_at`;
