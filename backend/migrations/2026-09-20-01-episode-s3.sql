-- 对象存储（S3/R2）托管：episode 增加远程副本字段
--
-- 语义：
--   s3_status = 0  未上传（或等待上传）
--   s3_status = 1  已上传（s3_key 有效，s3_size 与远端一致）
--   s3_status = 2  上传失败（s3_err 记录原因，可重试）
--
--   s3_remote_only = 1  该集从未下载到本地，仅存在于对象存储
--                        （补档时直接引用远程文件，file_path 为空串）
--   s3_purged_at        本地文件被删除的时间（上传校验通过后才会写）
--
-- 注意：file_path 是 NOT NULL，远程独有集写空串而不是 NULL。

ALTER TABLE `episode`
  ADD COLUMN `s3_key`         VARCHAR(1000)   DEFAULT NULL COMMENT '对象存储键，非空表示有远程副本' AFTER `remux_err`,
  ADD COLUMN `s3_size`        BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '远端对象字节数' AFTER `s3_key`,
  ADD COLUMN `s3_status`      TINYINT         NOT NULL DEFAULT 0 COMMENT '0未上传 1已上传 2失败' AFTER `s3_size`,
  ADD COLUMN `s3_err`         VARCHAR(255)    DEFAULT NULL COMMENT '上传失败原因' AFTER `s3_status`,
  ADD COLUMN `s3_uploaded_at` DATETIME        DEFAULT NULL COMMENT '上传完成时间' AFTER `s3_err`,
  ADD COLUMN `s3_purged_at`   DATETIME        DEFAULT NULL COMMENT '本地文件删除时间' AFTER `s3_uploaded_at`,
  ADD COLUMN `s3_attempts`    TINYINT         NOT NULL DEFAULT 0 COMMENT '上传尝试次数，超过上限不再自动重试' AFTER `s3_purged_at`,
  ADD COLUMN `s3_remote_only` TINYINT         NOT NULL DEFAULT 0 COMMENT '1=仅远程，本地从未落地' AFTER `s3_attempts`,
  ADD KEY `idx_s3_status` (`s3_status`),
  ADD KEY `idx_s3_key` (`s3_key`(191));
