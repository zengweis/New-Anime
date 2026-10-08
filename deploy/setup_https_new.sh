#!/usr/bin/env bash
# 新服务器 HTTPS 设置：修正 nginx 配置 + 签发 Let's Encrypt 证书
set -u

SITE=/etc/nginx/sites-available/anime-new
DIST=/opt/anime-new/frontend/dist
EMAIL=zeng@ratatoskr.cn
DOMAIN=www.acgn.help

echo "### 1. 放行 ACME 挑战目录（在 deny all 之前用 ^~ 前缀匹配）"
mkdir -p "$DIST/.well-known/acme-challenge"
chown -R www-data:www-data "$DIST/.well-known"

python3 - <<'PY'
p = '/etc/nginx/sites-available/anime-new'
s = open(p, encoding='utf-8').read()
if '/.well-known/acme-challenge/' not in s:
    block = '''
    # ---------------- ACME 挑战（必须在 deny . 之前，^~ 优先级更高）----------------
    location ^~ /.well-known/acme-challenge/ {
        root /opt/anime-new/frontend/dist;
        default_type "text/plain";
        try_files $uri =404;
    }
'''
    marker = '    location ~ /\\. {'
    if marker in s:
        s = s.replace(marker, block + '\n' + marker, 1)
    else:
        s = s.replace('    location / {', block + '\n    location / {', 1)
    open(p, 'w', encoding='utf-8').write(s)
    print('  已插入 ACME location')
else:
    print('  ACME location 已存在，跳过')
PY

echo
echo "### 2. 校验并 reload nginx"
nginx -t 2>&1 && systemctl reload nginx && echo "  reload ok"

echo
echo "### 3. 本地验证挑战路径"
echo ACME_PROBE_OK > "$DIST/.well-known/acme-challenge/probe"
chown www-data:www-data "$DIST/.well-known/acme-challenge/probe"
echo "  源站: $(curl -s -m 6 http://127.0.0.1/.well-known/acme-challenge/probe)"
echo "  经CF: $(curl -s -m 10 http://www.acgn.help/.well-known/acme-challenge/probe)"
rm -f "$DIST/.well-known/acme-challenge/probe"

echo
echo "### 4. 签发证书（webroot / HTTP-01）"
certbot certonly --webroot -w "$DIST" -d "$DOMAIN" \
    --email "$EMAIL" --agree-tos --non-interactive --keep-until-expiring 2>&1 | tail -25

echo
echo "### 5. 证书状态"
if [ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]; then
    echo "  证书已签发:"
    openssl x509 -in "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" -noout \
        -subject -issuer -dates 2>&1 | sed 's/^/    /'
else
    echo "  !! 证书未生成，检查上面的报错"
fi

echo
echo "HTTPS_STEP1_DONE"
