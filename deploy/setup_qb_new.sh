#!/usr/bin/env bash
# 在新服务器配置并启动 qBittorrent-nox（root 运行，WebUI 127.0.0.1:8080）
set -u

QB_PASS="${1:?需要传入 qb 明文密码}"
QB_HASH="${2:?需要传入 qb PBKDF2 哈希}"

echo "### 1. 配置目录"
mkdir -p /root/.config/qBittorrent
cat > /root/.config/qBittorrent/qBittorrent.conf <<EOF
[Application]
FileLogger\Enabled=true
FileLogger\Path=/root/.config/qBittorrent/logs

[BitTorrent]
Session\DefaultSavePath=/data/downloads/anime
Session\TempPath=/data/downloads/anime/.incomplete
Session\TempPathEnabled=true
Session\Port=6881
Session\QueueingSystemEnabled=false

[LegalNotice]
Accepted=true

[Preferences]
WebUI\Address=127.0.0.1
WebUI\Port=8080
WebUI\Username=admin
WebUI\Password_PBKDF2="$QB_HASH"
WebUI\LocalHostAuth=false
WebUI\CSRFProtection=true
WebUI\ClickjackingProtection=true
WebUI\HostHeaderValidation=true
WebUI\ServerDomains=127.0.0.1:8080,localhost:8080
Downloads\SavePath=/data/downloads/anime
Downloads\TempPathEnabled=true
Downloads\TempPath=/data/downloads/anime/.incomplete
General\Locale=zh
EOF
echo "  写入 /root/.config/qBittorrent/qBittorrent.conf"

echo
echo "### 2. 创建临时/存档目录（www-data 可读，remux 目录可写）"
mkdir -p /data/downloads/anime/.incomplete /data/downloads/anime/remux
chown -R root:root /data/downloads/anime
chmod 755 /data/downloads/anime
chown -R www-data:www-data /data/downloads/anime/remux
chmod 755 /data/downloads/anime/remux
ls -ld /data/downloads/anime /data/downloads/anime/.incomplete /data/downloads/anime/remux

echo
echo "### 3. 启动 qbittorrent-nox@root"
systemctl daemon-reload
systemctl enable --now qbittorrent-nox@root 2>&1
sleep 6
systemctl is-active qbittorrent-nox@root

echo
echo "### 4. WebUI 探测"
for i in 1 2 3 4 5 6 7 8; do
    code=$(curl -s -o /dev/null -m 5 -w '%{http_code}' http://127.0.0.1:8080/ || echo 000)
    echo "  第${i}次: HTTP $code"
    if [ "$code" = "200" ]; then break; fi
    sleep 4
done

echo
echo "### 5. 登录测试"
curl -s -m 8 -i -X POST http://127.0.0.1:8080/api/v2/auth/login \
    --data-urlencode "username=admin" --data-urlencode "password=$QB_PASS" 2>&1 | head -12

echo
echo "### 6. 版本"
curl -s -m 8 http://127.0.0.1:8080/api/v2/app/version
echo

echo
echo "### 7. 写入 app 配置（qb_pass）"
mysql anime_new -e "UPDATE setting SET v='$QB_PASS' WHERE k='qb_pass';"
mysql anime_new -e "SELECT k,v FROM setting WHERE k LIKE 'qb%' OR k='download_root';"

echo
echo "QB_SETUP_DONE"
