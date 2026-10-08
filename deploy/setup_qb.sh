#!/bin/bash
# ---------------------------------------------------------------
#  qBittorrent 初始化
#  密码在服务器本地生成，写入 qB 配置 + PHP setting，不经过终端输出
# ---------------------------------------------------------------
set -euo pipefail

CONF_DIR=/root/.config/qBittorrent
CONF="$CONF_DIR/qBittorrent.conf"
DL_ROOT=/data/downloads/anime
DL_TEMP=/data/downloads/temp

echo "== 1. 准备目录 =="
mkdir -p "$CONF_DIR" "$DL_ROOT" "$DL_TEMP" /var/log/qbittorrent
chmod 755 /data /data/downloads "$DL_ROOT" "$DL_TEMP"

echo "== 2. 停掉已有实例 =="
systemctl stop qbittorrent-nox@root 2>/dev/null || true
pkill -f qbittorrent-nox 2>/dev/null || true
sleep 2

echo "== 3. 生成 WebUI 密码与 PBKDF2 哈希 =="
QB_PW="$(openssl rand -hex 12)"
QB_HASH="$(php -r '
$pw   = $argv[1];
$salt = random_bytes(16);
$dk   = hash_pbkdf2("sha512", $pw, $salt, 100000, 64, true);
echo "@ByteArray(" . base64_encode($salt) . ":" . base64_encode($dk) . ")";
' "$QB_PW")"

echo "== 4. 写 qBittorrent.conf =="
cat > "$CONF" <<EOF
[Application]
FileLogger\\Enabled=true
FileLogger\\Path=/var/log/qbittorrent
FileLogger\\MaxSize=10485760
FileLogger\\Age=7

[BitTorrent]
Session\\DefaultSavePath=$DL_ROOT
Session\\TempPath=$DL_TEMP
Session\\TempPathEnabled=false
Session\\Port=51413
Session\\GlobalUPSpeedLimit=2048
Session\\GlobalDLSpeedLimit=0
Session\\QueueingSystemEnabled=false
Session\\AnonymousModeEnabled=false
Session\\Interface=
Session\\BTProtocol=TCP
BitTorrent\\DHTEnabled=true
BitTorrent\\PeXEnabled=true
BitTorrent\\LSDEnabled=true
BitTorrent\\MaxConnec=240
BitTorrent\\MaxConnecPerTorrent=60
BitTorrent\\MaxUploadsPerTorrent=4

[LegalNotice]
Accepted=true

[Preferences]
WebUI\\Address=127.0.0.1
WebUI\\Port=8080
WebUI\\LocalHostAuth=false
WebUI\\Username=admin
WebUI\\Password_PBKDF2="$QB_HASH"
WebUI\\CSRFProtection=true
WebUI\\HostHeaderValidation=false
WebUI\\ClickjackingProtection=true
WebUI\\MaxAuthenticationFailCount=5
WebUI\\BanDuration=3600
WebUI\\SessionTimeout=3600
WebUI\\AlternativeUIEnabled=false
Downloads\\SavePath=$DL_ROOT
Downloads\\TempPath=$DL_TEMP
Downloads\\TempPathEnabled=false
Downloads\\PreAllocation=true
Downloads\\UseIncompleteExtension=false
General\\Locale=zh_CN
EOF

chmod 600 "$CONF"

echo "== 5. 启动服务 =="
systemctl daemon-reload
systemctl enable qbittorrent-nox@root >/dev/null 2>&1 || true
systemctl restart qbittorrent-nox@root
sleep 6

echo "== 6. 写入 PHP 配置（不回显） =="
php -r '
require "/opt/anime-new/backend/src/bootstrap.php";
\App\Core\Setting::set("qb_url",      "http://127.0.0.1:8080");
\App\Core\Setting::set("qb_user",     "admin");
\App\Core\Setting::set("qb_pass",     $argv[1]);
\App\Core\Setting::set("qb_category", "anime");
\App\Core\Setting::set("download_root", "/data/downloads/anime");
' "$QB_PW"

# 备份一份密码到仅 root 可读的文件，方便日后登录 WebUI
echo "$QB_PW" > /root/.qb_webui_password
chmod 600 /root/.qb_webui_password

echo "== 7. 检查 =="
systemctl is-active qbittorrent-nox@root
ss -tlnp | grep 8080 || echo "!! 8080 未监听"
echo "WebUI 密码已保存在 /root/.qb_webui_password"
