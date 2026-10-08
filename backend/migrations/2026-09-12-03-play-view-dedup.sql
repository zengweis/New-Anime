-- 播放计次去重表
--
-- 背景：MKV 交给前端解封装播放后，浏览器会对同一个源文件发**大量** HTTP Range 请求
-- （每个分片一次，拖动进度条还会更多）。原来 stream.php 是「每请求 views+1」，
-- 一次播放就能把 views 从 0 刷到几百，统计彻底失去意义。
--
-- 改为：同一用户 + 同一剧集，30 分钟内只计一次。用唯一键 + 条件更新做原子判断，
-- 避免「先查后写」的竞态。
CREATE TABLE IF NOT EXISTS `play_view` (
  `user_id`    INT UNSIGNED NOT NULL,
  `episode_id` INT UNSIGNED NOT NULL,
  `counted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`episode_id`),
  KEY `idx_counted` (`counted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='播放计次去重（Range 请求洪水）';
