-- s3_task 增加「本地未清理干净」的原因字段
--
-- 上传成功后删本地文件这一步可能失败：unlink 取决于**目录**写权限，而下载目录里的
-- 文件通常是 qBittorrent 以另一个用户写下的。此时上传是成功的（远端副本可用），
-- 但磁盘没省下来 —— 这是两件事，必须分开记，否则界面上会显示「已上传并删除本地」
-- 而实际上文件还在。
--
-- 语义：status=1（上传成功）+ purge_error 非空 = 远端已就绪但本地没删掉。

ALTER TABLE `s3_task`
  ADD COLUMN `purge_error` VARCHAR(500) DEFAULT NULL
    COMMENT '上传成功后本地文件未能删除的原因（非空表示磁盘未释放）' AFTER `purged`;
