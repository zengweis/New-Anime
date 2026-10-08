set -u
echo "=== PHP exec 是否可用 ==="
for ini in /etc/php/*/fpm/php.ini /etc/php/*/cli/php.ini; do
  [ -f "$ini" ] || continue
  echo "--- $ini"
  grep -E "^\s*disable_functions" "$ini" || echo "    (未设置 disable_functions)"
done
echo
echo "=== PHP-FPM 运行用户 ==="
grep -rhnE "^\s*(user|group)\s*=" /etc/php/*/fpm/pool.d/*.conf 2>/dev/null
echo
echo "=== daemon 服务定义 ==="
systemctl cat anime-daemon.service 2>/dev/null | sed -n '1,30p'
echo
echo "=== 下载目录权限链 ==="
ls -ld /data /data/downloads /data/downloads/anime 2>&1
F="/data/downloads/anime/[LoliHouse] Yani Neko - 10 [WebRip 1080p HEVC-10bit AAC SRTx2].mkv"
namei -l "$F" 2>&1 | tail -8
echo
echo "=== 现有 nginx 位置块 ==="
ls /etc/nginx/sites-enabled/ 2>/dev/null
grep -rnE "location|alias|internal|root |fastcgi_pass" /etc/nginx/sites-enabled/ 2>/dev/null | head -40
echo
echo "=== setting 表 ==="
mysql anime_new -e "SELECT \`key\`, LEFT(\`value\`,60) AS value FROM setting ORDER BY \`key\`" 2>&1 | head -40
echo
echo "=== backend bin 目录 ==="
ls -la /opt/anime-new/backend/bin/
echo
echo "=== 站点目录属主 ==="
ls -ld /opt/anime-new /opt/anime-new/backend /opt/anime-new/frontend/dist
