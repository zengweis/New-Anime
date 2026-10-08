-- 对象存储（S3/R2）默认配置项
--
-- 只补默认值，已存在的配置一律不动（ON DUPLICATE KEY UPDATE k=k）。
-- 所有开关默认关闭，配好 endpoint/bucket/密钥后再开启 s3_enabled。

INSERT INTO `setting` (`k`,`v`) VALUES
  ('s3_enabled',        '0'),
  ('s3_endpoint',       ''),
  ('s3_region',         'auto'),
  ('s3_bucket',         ''),
  ('s3_access_key',     ''),
  ('s3_secret_key',     ''),
  ('s3_prefix',         'anime'),
  ('s3_public_base',    ''),
  ('s3_url_mode',       'public'),
  ('s3_auto_upload',    '1'),
  ('s3_delete_local',   '1'),
  ('s3_require_remux',  '1'),
  ('s3_upload_limit',   '2')
ON DUPLICATE KEY UPDATE `k` = `k`;
