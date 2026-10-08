-- 磁力链接加种时可能带大量 tracker 参数（用户手动补录粘贴的完整 magnet），
-- 超过原 VARCHAR(1000) 限制，导致 Data too long 报错。扩到 5000 足够容纳。
ALTER TABLE `rss_item`
  MODIFY `magnet` VARCHAR(5000) DEFAULT NULL;
