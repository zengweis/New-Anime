#!/bin/bash
# ---------------------------------------------------------------
#  nginx + php-fpm 配置（2 核 3.6G 机器调优）
# ---------------------------------------------------------------
set -euo pipefail

SRC_DIR="$(cd "$(dirname "$0")" && pwd)"
FPM_POOL=/etc/php/8.5/fpm/pool.d/www.conf
PHP_INI=/etc/php/8.5/fpm/php.ini

echo "== 1. nginx 站点 =="
install -m 644 "$SRC_DIR/nginx-anime.conf" /etc/nginx/sites-available/anime-new
ln -sf /etc/nginx/sites-available/anime-new /etc/nginx/sites-enabled/anime-new
rm -f /etc/nginx/sites-enabled/default

# 前端目录先占位，避免 nginx 启动失败
mkdir -p /opt/anime-new/frontend/dist
if [ ! -f /opt/anime-new/frontend/dist/index.html ]; then
    echo '<!doctype html><meta charset="utf-8"><title>Anime New</title><p>前端尚未构建</p>' \
        > /opt/anime-new/frontend/dist/index.html
fi
chown -R www-data:www-data /opt/anime-new/frontend

echo "== 2. php-fpm 进程池调优 =="
cp -n "$FPM_POOL" "$FPM_POOL.orig" 2>/dev/null || true

set_ini() {
    local key="$1" val="$2" file="$3"
    if grep -qE "^[;[:space:]]*${key}[[:space:]]*=" "$file"; then
        sed -i -E "s|^[;[:space:]]*${key}[[:space:]]*=.*|${key} = ${val}|" "$file"
    else
        echo "${key} = ${val}" >> "$file"
    fi
}

set_ini pm "dynamic" "$FPM_POOL"
set_ini pm.max_children 12 "$FPM_POOL"
set_ini pm.start_servers 3 "$FPM_POOL"
set_ini pm.min_spare_servers 2 "$FPM_POOL"
set_ini pm.max_spare_servers 6 "$FPM_POOL"
set_ini pm.max_requests 500 "$FPM_POOL"
set_ini request_terminate_timeout "300s" "$FPM_POOL"
set_ini "php_admin_value[memory_limit]" "256M" "$FPM_POOL"
set_ini "php_admin_value[date.timezone]" "Asia/Shanghai" "$FPM_POOL"

echo "== 3. php.ini =="
set_ini memory_limit "256M" "$PHP_INI"
set_ini date.timezone "Asia/Shanghai" "$PHP_INI"
set_ini upload_max_filesize "32M" "$PHP_INI"
set_ini post_max_size "32M" "$PHP_INI"
set_ini expose_php "Off" "$PHP_INI"

echo "== 4. 重载服务 =="
nginx -t
systemctl reload nginx
systemctl restart php8.5-fpm

echo "== 5. 状态 =="
systemctl is-active nginx php8.5-fpm
ss -tlnp | grep -E ":80 |:8080 " || true
