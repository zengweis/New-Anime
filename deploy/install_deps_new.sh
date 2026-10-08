#!/bin/bash
set -e
export DEBIAN_FRONTEND=noninteractive

echo "### 1. apt update"
apt-get update -y

echo "### 2. 基础工具"
apt-get install -y software-properties-common curl gnupg ca-certificates lsb-release unzip

echo "### 3. ondrej PPA (PHP 8.5)"
add-apt-repository -y ppa:ondrej/php
apt-get update -y

echo "### 4. 主体软件"
apt-get install -y \
  nginx mysql-server ffmpeg rclone \
  certbot python3-certbot-nginx \
  php8.5-fpm php8.5-cli php8.5-mysql php8.5-curl php8.5-mbstring \
  php8.5-xml php8.5-zip php8.5-gd php8.5-intl

echo "### 5. qbittorrent-nox"
apt-get install -y qbittorrent-nox

echo "### 版本确认"
nginx -v 2>&1
php -v | head -1
mysql --version
ffmpeg -version | head -1
rclone version | head -1
qbittorrent-nox --version 2>&1 | head -1
echo "### INSTALL_DONE"
