-- 弹幕
--
-- 定位：与视频字节流完全无关的旁路数据 —— 不参与 remux、上传、解码链路，
-- 纯 HTTP JSON + 前端 canvas 叠加。所以它不给这台 2 核机器增加任何处理负担。
--
-- 规则（2026-09-24 定）：
--   · 只有登录用户能发，user_id 必填（要能追到是哪个人发的哪条）
--   · 不做审核，写入即可见；后台可批量删除（软删 status=9，误删还能救回来）
--   · 后台可导入（管理页上传或 CLI），导入的弹幕挂在执行导入的管理员名下，source='import'
--   · 不做时间轴偏移/修正 —— 因此这里没有 offset 字段
--
-- 索引说明：
--   idx_ep_time  读路径主索引：按集取可见弹幕并按时间排序
--   idx_user_time 限速与「我的弹幕」：按用户查最近发送
--   idx_ep_user  单集单用户配额统计

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

-- 刻意不建 user_id 外键（与 wish 的 CASCADE 不同）：
-- 用户注销不该把别人的观看体验一起删掉，弹幕保留、user_id 悬空即可。
