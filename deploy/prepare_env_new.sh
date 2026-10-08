#!/bin/bash
set -e
export DEBIAN_FRONTEND=noninteractive

echo "### 1. 目录结构"
mkdir -p /opt/anime-new/backend
mkdir -p /opt/anime-new/frontend-src
mkdir -p /opt/anime-new/frontend/dist
mkdir -p /opt/anime-new/deploy
mkdir -p /opt/anime-new/storage/logs
mkdir -p /data/downloads/anime/remux
mkdir -p /data/anime-subs
chown -R www-data:www-data /opt/anime-new/storage /data/anime-subs
chmod 775 /opt/anime-new/storage /opt/anime-new/storage/logs

echo "### 2. Node 22"
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt-get install -y nodejs

echo "### 3. 版本"
node -v
npm -v
nginx -v 2>&1
php -v | head -1
echo "### NODE_DONE"
