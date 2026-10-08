-- 规则级「保存后自动下载」开关
-- 已有库升级用；新库由 schema.sql 直接建出该列。
ALTER TABLE `match_rule`
  ADD COLUMN `auto_download` TINYINT NOT NULL DEFAULT 1
      COMMENT '保存规则后立即下载命中条目' AFTER `enabled`;
