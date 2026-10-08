-- 服务端抽取的字幕轨
--
-- 为什么不在前端抽：mkv 内嵌字幕轨要从前端解封装引擎里扫全片才能拿到，
-- 实测约 1.26x 实时（一集 23 分钟要等约 19 分钟才出字幕）。
-- 服务端 ffmpeg 只解封装不解视频码，实测 0.48 秒，且可一次性缓存复用。
-- 所以字幕一律服务端抽好落盘，浏览器只当普通 <track> 拉一个几十 KB 的 VTT。
CREATE TABLE IF NOT EXISTS `episode_subtitle` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `episode_id`   INT UNSIGNED NOT NULL,
  `track_index`  INT NOT NULL COMMENT '第几条字幕轨，从 0 起，对应 ffmpeg -map 0:s:N',
  `stream_index` INT NOT NULL COMMENT '容器内绝对流索引',
  `codec`        VARCHAR(24)  DEFAULT NULL COMMENT '源字幕编码 subrip/ass/hdmv_pgs_subtitle',
  `language`     VARCHAR(24)  DEFAULT NULL COMMENT 'ISO 语言码，如 chi/zho/jpn',
  `label`        VARCHAR(191) DEFAULT NULL COMMENT '展示名，优先取流标题（简体中文/繁體中文）',
  `file`         VARCHAR(191) NOT NULL COMMENT '相对 subtitle_root 的文件名',
  `cues`         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '字幕条数',
  `bytes`        INT UNSIGNED NOT NULL DEFAULT 0,
  `source_mtime` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '抽取时源文件 mtime，变了说明文件被替换需重抽',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ep_track` (`episode_id`,`track_index`),
  KEY `idx_episode` (`episode_id`),
  CONSTRAINT `fk_subtitle_episode` FOREIGN KEY (`episode_id`)
    REFERENCES `episode` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='服务端抽取的字幕轨';
