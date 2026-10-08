-- 对象存储任务日志：记录每一次上传/远程引用的执行情况，供后台「上传任务」页实时展示。
--
-- 设计要点：
--   1. **不建外键**。剧集被删掉之后，这条历史仍然值得保留（「这集传过没有」是运维问题），
--      所以剧名/集数冗余存一份快照，而不是靠 join。
--   2. **进度字段放在行内**（bytes_done / parts_done）。上传是 daemon 里的长任务，
--      后台只能轮询数据库；把进度写在同一行最省事，也避免为进度再开一张表。
--   3. status: 0=进行中 1=成功 2=失败。daemon 进程若被 kill，会留下 status=0 的僵尸行，
--      展示时用「开始时间过久」判定为中断（见 S3TaskLog::staleSeconds）。

CREATE TABLE IF NOT EXISTS `s3_task` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `episode_id`  INT UNSIGNED DEFAULT NULL COMMENT '剧集 id（剧集删除后仍保留此历史）',
  `anime_id`    INT UNSIGNED DEFAULT NULL,
  `anime_title` VARCHAR(200) DEFAULT NULL COMMENT '番剧名快照',
  `ep_no`       DECIMAL(6,1) DEFAULT NULL,
  `ep_title`    VARCHAR(500) DEFAULT NULL COMMENT '剧集标题快照',
  `action`      VARCHAR(24)  NOT NULL DEFAULT 'upload' COMMENT 'upload/purge/import_remote/delete_remote',
  `status`      TINYINT      NOT NULL DEFAULT 0 COMMENT '0进行中 1成功 2失败',
  `source`      VARCHAR(255) DEFAULT NULL COMMENT '本地来源文件名',
  `s3_key`      VARCHAR(1000) DEFAULT NULL,
  `bytes_total` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '待传总字节',
  `bytes_done`  BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已传字节（分片进行中会更新）',
  `parts_total` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `parts_done`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `multipart`   TINYINT      NOT NULL DEFAULT 0,
  `purged`      TINYINT      NOT NULL DEFAULT 0 COMMENT '是否顺带删除了本地文件',
  `error`       VARCHAR(500) DEFAULT NULL,
  `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
  `trigger`     VARCHAR(16)  NOT NULL DEFAULT 'auto' COMMENT 'auto 常驻进程 / manual 手动',
  `actor`       VARCHAR(64)  DEFAULT NULL COMMENT '手动触发时的操作人',
  `started_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status`  (`status`, `id`),
  KEY `idx_started` (`started_at`),
  KEY `idx_episode` (`episode_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='对象存储任务日志';
