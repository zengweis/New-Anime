-- qBittorrent：下载完成后自动暂停做种（省服务器上行流量）
--
-- 默认开启：本项目是用来看片的，下载完的种子继续做种只会白吃上行带宽。
-- 已存在的配置不会被覆盖（ON DUPLICATE KEY UPDATE k=k）。
--
-- 语义：
--   '1' 常驻进程发现「已完成且仍在做种」的任务就暂停它（最多晚 60 秒）
--   '0' 保持 qBittorrent 默认行为，继续做种

INSERT INTO `setting` (`k`,`v`) VALUES
  ('qb_pause_after_done', '1')
ON DUPLICATE KEY UPDATE `k` = `k`;
