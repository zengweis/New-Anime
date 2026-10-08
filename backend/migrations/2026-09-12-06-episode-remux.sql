-- 服务端 remux 状态：把 MKV 一次性 remux 成 MP4（ffmpeg -c copy 零转码），
-- 浏览器原生 <video> 播放，绕开前端解封装（playsvideo）的 segment 边界音频时间戳跳变
-- 导致的「音频隔几秒断 0.x 秒」问题。
--
-- remux_path 非空且文件存在 = 可原生播放；remux_err 记录失败原因。
ALTER TABLE `episode`
  ADD COLUMN `remux_path` VARCHAR(1000) DEFAULT NULL COMMENT 'remux 后的 MP4 路径，非空表示可原生播放' AFTER `sub_extract_err`,
  ADD COLUMN `remux_err`   VARCHAR(255)  DEFAULT NULL COMMENT 'remux 失败原因' AFTER `remux_path`;
