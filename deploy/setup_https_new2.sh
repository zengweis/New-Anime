#!/usr/bin/env bash
# 1) 添加 certbot deploy hook（续期后 reload nginx）
# 2) 尝试把裸域 acgn.help 加入证书 SAN
set -u

echo "### 1. certbot deploy hook"
HOOK=/etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
mkdir -p /etc/letsencrypt/renewal-hooks/deploy
cat > "$HOOK" <<'EOF'
#!/usr/bin/env bash
# 证书续期/部署后重载 nginx
systemctl reload nginx 2>/dev/null || systemctl restart nginx
logger -t certbot-hook "anime-new: nginx reloaded after cert deploy"
EOF
chmod +x "$HOOK"
echo "  已写入 $HOOK"
ls -l /etc/letsencrypt/renewal-hooks/deploy/

echo
echo "### 2. 扩展证书到裸域 acgn.help"
certbot certonly --webroot -w /opt/anime-new/frontend/dist \
    -d www.acgn.help -d acgn.help \
    --email zeng@ratatoskr.cn --agree-tos --non-interactive --expand 2>&1 | tail -22

echo
echo "### 3. 证书 SAN 检查"
openssl x509 -in /etc/letsencrypt/live/www.acgn.help/fullchain.pem -noout \
    -subject -dates -ext subjectAltName 2>&1 | sed 's/^/  /'

echo
echo "### 4. 续期配置文件（确认新域已登记）"
grep -E "webroot_map|^www|^acgn|domains" -A3 /etc/letsencrypt/renewal/www.acgn.help.conf 2>/dev/null | sed 's/^/  /'

echo
echo "### 5. nginx reload"
nginx -t 2>&1 | sed 's/^/  /' && systemctl reload nginx && echo "  reload ok"

echo
echo "HTTPS_STEP2_DONE"
