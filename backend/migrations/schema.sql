-- ============================================================
--  anime-new  schema v2  (多用户 + 邀请注册 + SMTP)
--  MySQL 5.7+ / 8.0  utf8mb4
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------
-- 用户
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(64)  NOT NULL,
  `email`         VARCHAR(191) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `nickname`      VARCHAR(64)  DEFAULT NULL,
  `avatar`        VARCHAR(500) DEFAULT NULL,
  `role`          ENUM('admin','user') NOT NULL DEFAULT 'user',
  `status`        TINYINT NOT NULL DEFAULT 1 COMMENT '0待验证 1正常 2待审核 9禁用',
  `invite_code_id` INT UNSIGNED DEFAULT NULL COMMENT '注册所用邀请码',
  `reg_ip`        VARCHAR(45)  DEFAULT NULL,
  `last_login`    DATETIME     DEFAULT NULL,
  `last_ip`       VARCHAR(45)  DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_email` (`email`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户';

-- ---------------------------------------------------------------
-- 邀请码
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invite_code` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(32)  NOT NULL,
  `max_uses`    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `used_count`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `bound_email` VARCHAR(191) DEFAULT NULL COMMENT '定向邀请，空=不限制',
  `expires_at`  DATETIME     DEFAULT NULL,
  `status`      TINYINT NOT NULL DEFAULT 1 COMMENT '1可用 0作废',
  `note`        VARCHAR(200) DEFAULT NULL,
  `created_by`  INT UNSIGNED DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邀请码';

-- ---------------------------------------------------------------
-- 一次性令牌（邮箱验证 / 找回密码）
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_token` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `email`      VARCHAR(191) NOT NULL,
  `token_hash` CHAR(64)     NOT NULL COMMENT 'sha256',
  `type`       ENUM('verify','reset') NOT NULL,
  `expires_at` DATETIME     NOT NULL,
  `used_at`    DATETIME     DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token_hash`),
  KEY `idx_user_type` (`user_id`,`type`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮箱令牌';

-- ---------------------------------------------------------------
-- 邮件发送队列（异步）
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mail_queue` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `to_email`   VARCHAR(191) NOT NULL,
  `to_name`    VARCHAR(64)  DEFAULT NULL,
  `subject`    VARCHAR(255) NOT NULL,
  `body`       MEDIUMTEXT   NOT NULL,
  `status`     TINYINT NOT NULL DEFAULT 0 COMMENT '0待发 1已发 2失败',
  `retry`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `error`      VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at`    DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`,`created_at`),
  KEY `idx_email_time` (`to_email`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮件队列';

-- ---------------------------------------------------------------
-- 追番（收藏）
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_favorite` (
  `user_id`    INT UNSIGNED NOT NULL,
  `anime_id`   INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`anime_id`),
  KEY `idx_anime` (`anime_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='追番';

-- ---------------------------------------------------------------
-- 观看进度
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_history` (
  `user_id`      INT UNSIGNED NOT NULL,
  `episode_id`   INT UNSIGNED NOT NULL,
  `anime_id`     INT UNSIGNED NOT NULL,
  `position_sec` INT UNSIGNED NOT NULL DEFAULT 0,
  `duration_sec` INT UNSIGNED NOT NULL DEFAULT 0,
  `finished`     TINYINT NOT NULL DEFAULT 0,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`episode_id`),
  KEY `idx_user_time` (`user_id`,`updated_at`),
  KEY `idx_anime` (`anime_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='观看进度';

-- ---------------------------------------------------------------
-- 播放计次去重
-- MKV 走前端解封装后浏览器会对同一文件发大量 Range 请求，
-- 不按「用户+剧集+时间窗」去重的话 views 会瞬间爆掉。
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `play_view` (
  `user_id`    INT UNSIGNED NOT NULL,
  `episode_id` INT UNSIGNED NOT NULL,
  `counted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`episode_id`),
  KEY `idx_counted` (`counted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='播放计次去重（Range 请求洪水）';

-- ---------------------------------------------------------------
-- 番剧
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `anime` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(255) NOT NULL COMMENT '归一化主标题',
  `title_cn`   VARCHAR(255) DEFAULT NULL,
  `alias`      VARCHAR(500) DEFAULT NULL COMMENT '别名，| 分隔',
  `cover`      VARCHAR(500) DEFAULT NULL,
  `year`       SMALLINT     DEFAULT NULL,
  `season`     TINYINT      DEFAULT NULL COMMENT '1春 2夏 3秋 4冬',
  `summary`    TEXT,
  `status`     TINYINT      NOT NULL DEFAULT 0 COMMENT '0连载 1完结',
  `ep_count`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `last_ep`    DECIMAL(6,1) DEFAULT NULL,
  `updated_at` DATETIME     DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_title_year` (`title`,`year`),
  KEY `idx_year_season` (`year`,`season`),
  KEY `idx_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='番剧';

-- ---------------------------------------------------------------
-- 集数
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `episode` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `anime_id`      INT UNSIGNED NOT NULL,
  `ep_no`         DECIMAL(6,1) NOT NULL DEFAULT 0,
  `title`         VARCHAR(500) DEFAULT NULL,
  `file_path`     VARCHAR(1000) NOT NULL,
  `file_size`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `infohash`      CHAR(40)     DEFAULT NULL,
  `subgroup`      VARCHAR(100) DEFAULT NULL,
  `resolution`    VARCHAR(20)  DEFAULT NULL,
  `vcodec`        VARCHAR(20)  DEFAULT NULL COMMENT '视频编码 hevc/h264/av1/vp9',
  `acodec`        VARCHAR(20)  DEFAULT NULL COMMENT '音频编码 aac/flac/opus/ac3/eac3',
  `pix_fmt`       VARCHAR(24)  DEFAULT NULL COMMENT '像素格式，yuv420p10le 即 10bit',
  `sub_tracks`    TINYINT      NOT NULL DEFAULT 0 COMMENT '内嵌字幕轨数量',
  `probe_at`      DATETIME     DEFAULT NULL COMMENT '最近一次探测时间',
  `probe_error`   VARCHAR(255) DEFAULT NULL COMMENT '探测失败原因',
  `subtitle_lang` VARCHAR(20)  DEFAULT NULL,
  `duration`      INT UNSIGNED DEFAULT NULL,
  `views`         INT UNSIGNED NOT NULL DEFAULT 0,
  `published_at`  DATETIME     DEFAULT NULL,
  `sub_extract_at`  DATETIME     DEFAULT NULL COMMENT '最近一次尝试抽取字幕的时间',
  `sub_extract_err` VARCHAR(255) DEFAULT NULL COMMENT '字幕抽取失败原因，NULL 表示成功或无字幕',
  `remux_path`      VARCHAR(1000) DEFAULT NULL COMMENT 'remux 后的 MP4 路径，非空表示可原生播放',
  `remux_err`       VARCHAR(255)  DEFAULT NULL COMMENT 'remux 失败原因',
  `s3_key`          VARCHAR(1000) DEFAULT NULL COMMENT '对象存储键，非空表示有远程副本',
  `s3_size`         BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '远端对象字节数',
  `s3_status`       TINYINT       NOT NULL DEFAULT 0 COMMENT '0未上传 1已上传 2失败',
  `s3_err`          VARCHAR(255)  DEFAULT NULL COMMENT '上传失败原因',
  `s3_uploaded_at`  DATETIME      DEFAULT NULL COMMENT '上传完成时间',
  `s3_purged_at`    DATETIME      DEFAULT NULL COMMENT '本地文件删除时间',
  `s3_attempts`     TINYINT       NOT NULL DEFAULT 0 COMMENT '上传尝试次数，超过上限不再自动重试',
  `s3_remote_only`  TINYINT       NOT NULL DEFAULT 0 COMMENT '1=仅远程，本地从未落地',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_hash_ep` (`infohash`,`ep_no`,`subgroup`),
  KEY `idx_anime_ep` (`anime_id`,`ep_no`),
  KEY `idx_created` (`created_at`),
  KEY `idx_s3_status` (`s3_status`),
  CONSTRAINT `fk_ep_anime` FOREIGN KEY (`anime_id`) REFERENCES `anime` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='集数';

-- ---------------------------------------------------------------
-- 服务端抽取的字幕轨
--
-- 前端从 mkv 里扫内嵌字幕约 1.26x 实时（一集要等约 19 分钟），
-- 服务端 ffmpeg 仅解封装实测 0.48 秒，故一律服务端抽好缓存，
-- 浏览器只拉几十 KB 的 WebVTT，零转码、零解码开销。
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `episode_subtitle` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `episode_id`   INT UNSIGNED NOT NULL,
  `track_index`  INT NOT NULL COMMENT '第几条字幕轨，从 0 起，对应 ffmpeg -map 0:s:N',
  `stream_index` INT NOT NULL COMMENT '容器内绝对流索引',
  `codec`        VARCHAR(24)  DEFAULT NULL COMMENT '源字幕编码 subrip/ass/hdmv_pgs_subtitle',
  `kind`         VARCHAR(8)   NOT NULL DEFAULT 'text' COMMENT 'text=WebVTT 文本 / image=位图字幕图片叠加层',
  `language`     VARCHAR(24)  DEFAULT NULL COMMENT 'ISO 语言码，如 chi/zho/jpn',
  `label`        VARCHAR(191) DEFAULT NULL COMMENT '展示名，优先取流标题',
  `file`         VARCHAR(191) NOT NULL COMMENT '相对 subtitle_root 的文件名',
  `cues`         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '字幕条数',
  `bytes`        INT UNSIGNED NOT NULL DEFAULT 0,
  `source_mtime` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '抽取时源文件 mtime',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ep_track` (`episode_id`,`track_index`),
  KEY `idx_episode` (`episode_id`),
  CONSTRAINT `fk_subtitle_episode` FOREIGN KEY (`episode_id`)
    REFERENCES `episode` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='服务端抽取的字幕轨';

-- ---------------------------------------------------------------
-- 弹幕
-- 与视频字节流完全无关的旁路数据（纯 HTTP JSON + 前端 canvas 叠加），
-- 不参与 remux / 上传 / 解码链路。只有登录用户能发；不做审核，
-- 后台可批量删除（软删 status=9）。刻意不建 user_id 外键：
-- 用户注销不该连带删掉别人的观看记录。
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `danmaku` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `episode_id` INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL COMMENT '发送者，必须登录',
  `t_ms`       INT UNSIGNED NOT NULL COMMENT '相对正片起点的毫秒',
  `mode`       TINYINT NOT NULL DEFAULT 1 COMMENT '1滚动 4底部固定 5顶部固定',
  `color`      INT UNSIGNED NOT NULL DEFAULT 16777215 COMMENT '0xRRGGBB',
  `size`       TINYINT NOT NULL DEFAULT 1 COMMENT '0小 1中 2大',
  `text`       VARCHAR(120) NOT NULL,
  `status`     TINYINT NOT NULL DEFAULT 0 COMMENT '0正常 9已删除（软删）',
  `source`     VARCHAR(16) NOT NULL DEFAULT 'web' COMMENT 'web=站内发送 / import=导入',
  `ip`         VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ep_time` (`episode_id`, `status`, `t_ms`),
  KEY `idx_user_time` (`user_id`, `created_at`),
  KEY `idx_ep_user` (`episode_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='弹幕';

-- ---------------------------------------------------------------
-- RSS 源
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rss_feed` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100) NOT NULL,
  `url`        VARCHAR(1000) NOT NULL,
  `enabled`    TINYINT NOT NULL DEFAULT 1,
  `auto_down`  TINYINT NOT NULL DEFAULT 1 COMMENT '1=命中规则即自动加种',
  `filter_re`  VARCHAR(500) DEFAULT NULL,
  `exclude_re` VARCHAR(500) DEFAULT NULL,
  `min_res`    VARCHAR(10)  DEFAULT '1080',
  `last_fetch` DATETIME     DEFAULT NULL,
  `last_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='RSS源';

-- ---------------------------------------------------------------
-- RSS 条目
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rss_item` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `feed_id`        INT UNSIGNED DEFAULT NULL,
  `guid`           VARCHAR(191) NOT NULL,
  `title`          VARCHAR(500) NOT NULL,
  `magnet`         VARCHAR(5000) DEFAULT NULL,
  `infohash`       CHAR(40)     DEFAULT NULL,
  `detail_url`     VARCHAR(500) DEFAULT NULL,
  `filesize`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `subgroup`       VARCHAR(100) DEFAULT NULL,
  `ep_no`          DECIMAL(6,1) DEFAULT NULL,
  `resolution`     VARCHAR(20)  DEFAULT NULL,
  `subtitle_lang`  VARCHAR(20)  DEFAULT NULL,
  `status`         TINYINT NOT NULL DEFAULT 0 COMMENT '0待处理 1已加种 2已入库 3忽略 4失败',
  `message`        VARCHAR(500) DEFAULT NULL,
  `matched_rule_id` INT UNSIGNED DEFAULT NULL,
  `anime_id`       INT UNSIGNED DEFAULT NULL,
  `torrent_hash`   CHAR(40)     DEFAULT NULL,
  `added_at`       DATETIME     DEFAULT NULL COMMENT '加种时间，用于小时限流',
  `pub_date`       DATETIME     DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_guid` (`guid`),
  KEY `idx_status` (`status`),
  KEY `idx_pub` (`pub_date`),
  KEY `idx_hash` (`infohash`),
  KEY `idx_anime` (`anime_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='RSS条目';

-- ---------------------------------------------------------------
-- 匹配规则
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `match_rule` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `anime_id`   INT UNSIGNED NOT NULL,
  `pattern`    VARCHAR(500) NOT NULL COMMENT 'PCRE 正则',
  `flags`      VARCHAR(10)  NOT NULL DEFAULT 'iu',
  `ep_capture` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '集号所在捕获组，0=自动猜',
  `priority`   INT NOT NULL DEFAULT 10,
  `enabled`    TINYINT NOT NULL DEFAULT 1,
  `auto_download` TINYINT NOT NULL DEFAULT 1 COMMENT '保存规则后立即下载命中条目',
  `hits`       INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_anime` (`anime_id`),
  KEY `idx_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='标题匹配规则';

-- ---------------------------------------------------------------
-- 配置
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `setting` (
  `k` VARCHAR(64) NOT NULL,
  `v` TEXT,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='配置';

-- ---------------------------------------------------------------
-- 任务日志
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `job_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job`        VARCHAR(50) NOT NULL,
  `ok`         TINYINT NOT NULL DEFAULT 1,
  `new_count`  INT NOT NULL DEFAULT 0,
  `message`    VARCHAR(1000) DEFAULT NULL,
  `cost_ms`    INT UNSIGNED DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_job_time` (`job`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='任务日志';

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
--  默认配置
-- ============================================================
INSERT INTO `setting` (`k`,`v`) VALUES
  ('site_name',              'Anime New'),
  ('site_url',               'http://<server-ip>'),
  ('register_enabled',       '1'),
  ('register_invite',        '1'),
  ('register_verify_email',  '1'),
  ('register_need_approve',  '0'),
  ('register_default_role',  'user'),
  ('qb_url',                 'http://127.0.0.1:8080'),
  ('qb_user',                'admin'),
  ('qb_pass',                ''),
  ('qb_category',            'anime'),
  ('qb_pause_after_done',    '1'),
  ('download_root',          '/data/downloads/anime'),
  ('video_ext',              'mp4,mkv,avi,webm,flv,ts,mov,m4v'),
  ('dedupe_by_ep',           '1'),
  ('min_resolution',         '1080'),
  ('hourly_add_limit',       '30'),
  ('smtp_host',              ''),
  ('smtp_port',              '465'),
  ('smtp_secure',            'ssl'),
  ('smtp_user',              ''),
  ('smtp_pass',              ''),
  ('smtp_from',              ''),
  ('smtp_from_name',         'Anime New'),
  ('auto_fetch',             '1'),
  ('fetch_interval_min',     '30'),
  ('ffmpeg_path',            ''),
  ('subs_bitmap_mode',       'image'),
  ('subs_img_format',        'webp'),
  ('subs_img_quality',       '90'),
  ('subs_img_threshold',     '0.02'),
  ('subs_py_bin',            ''),
  ('subs_ocr_bitmap',        '1'),
  ('subs_sup_keep',          'primary'),
  ('subs_ocr_bin',           '/opt/anime-new/venv-ocr/bin/pgsrip'),
  ('subs_tessdata',          ''),
  ('s3_enabled',             '0'),
  ('s3_endpoint',            ''),
  ('s3_region',              'auto'),
  ('s3_bucket',              ''),
  ('s3_access_key',          ''),
  ('s3_secret_key',          ''),
  ('s3_prefix',              'anime'),
  ('s3_public_base',         ''),
  ('s3_url_mode',            'public'),
  ('s3_auto_upload',         '1'),
  ('s3_delete_local',        '1'),
  ('s3_require_remux',       '1'),
  ('s3_upload_limit',        '2')
ON DUPLICATE KEY UPDATE `k`=`k`;

-- ---------------------------------------------------------------------------
-- 许愿墙：用户许愿想看的动漫，管理员标记结果（已实现/已拒绝）
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- 迁移记录（bin/migrate.php 使用；schema.sql 只负责全新建库）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `schema_migration` (
  `file`       VARCHAR(191) NOT NULL COMMENT 'migrations/ 下的文件名',
  `batch`      INT NOT NULL DEFAULT 1,
  `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`file`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='迁移执行记录';

-- ---------------------------------------------------------------------------
-- 对象存储任务日志：后台「上传任务」页的数据源
-- ---------------------------------------------------------------------------
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
  `purge_error` VARCHAR(500) DEFAULT NULL COMMENT '本地未能删除的原因（非空表示磁盘未释放）',
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
