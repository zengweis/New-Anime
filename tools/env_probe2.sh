set -u
echo "=== setting 表结构 ==="
mysql anime_new -e "DESCRIBE setting" 2>&1
echo
echo "=== setting 内容 ==="
mysql anime_new -e "SELECT * FROM setting ORDER BY 1" 2>&1 | head -30
echo
echo "=== episode 表字段 ==="
mysql anime_new -e "SHOW COLUMNS FROM episode" 2>&1
echo
echo "=== 已存在的表 ==="
mysql anime_new -e "SHOW TABLES" 2>&1
echo
echo "=== 该集记录 ==="
mysql anime_new -e "SELECT id, anime_id, ep_no, LEFT(title,55) t, file_path, video_codec, audio_codec, pix_fmt, container, subtitle_lang, probe_at FROM episode\G" 2>&1 | head -30
echo
echo "=== nginx 站点配置（跟随符号链接）==="
grep -RnE "listen|server_name|root |location|alias|internal|include|fastcgi_pass" /etc/nginx/sites-enabled/ 2>/dev/null | head -40
echo
echo "=== nginx conf.d ==="
ls /etc/nginx/conf.d/ 2>/dev/null
