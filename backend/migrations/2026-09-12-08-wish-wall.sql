-- 许愿墙：用户许愿想看的动漫，管理员标记结果（已实现/已拒绝）。
CREATE TABLE IF NOT EXISTS `wish` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `anime_name`  VARCHAR(200) NOT NULL COMMENT '想看的动漫名称',
  `note`        VARCHAR(500) DEFAULT NULL COMMENT '补充说明（可选）',
  `status`      TINYINT NOT NULL DEFAULT 0 COMMENT '0许愿中 1已实现 2已拒绝',
  `result_note` VARCHAR(255) DEFAULT NULL COMMENT '管理员标记结果时的说明',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`),
  CONSTRAINT `fk_wish_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='许愿墙';
