set -u
B=/opt/anime-new/backend
cd "$B"

echo "=== 以 www-data 身份抽取（与 daemon/fpm 同权限）==="
sudo -u www-data php bin/extract_subs.php --episode=25 2>&1

echo
echo "=== 落库结果 ==="
mysql anime_new -e "SELECT id, episode_id, track_index, stream_index, codec, language, label, file, cues, bytes FROM episode_subtitle\G" 2>&1

echo
echo "=== episode 抽取状态 ==="
mysql anime_new -e "SELECT id, sub_tracks, sub_extract_at, sub_extract_err FROM episode" 2>&1

echo
echo "=== 落盘文件 ==="
ls -lR /data/anime-subs 2>&1

echo
echo "=== 幂等测试：再抽一次应为 cached ==="
cd "$B" && time sudo -u www-data php bin/extract_subs.php --episode=25 2>&1 | tail -5

echo
echo "=== VTT 内容抽样 ==="
head -6 /data/anime-subs/25/t0.vtt 2>&1
echo "--- 末条 ---"
tail -5 /data/anime-subs/25/t0.vtt 2>&1

echo
echo "=== nginx 配置真实路径 ==="
readlink -f /etc/nginx/sites-enabled/anime-new
