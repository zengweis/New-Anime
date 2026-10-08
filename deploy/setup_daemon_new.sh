#!/bin/bash
# 配置 site_url + daemon systemd 服务
set -e

echo "### site_url"
cd /opt/anime-new/backend
php -r 'require "src/bootstrap.php"; use App\Core\Setting; Setting::set("site_url","https://www.acgn.help"); echo "site_url = ", Setting::get("site_url"), "\n";'

echo "### daemon 日志权限"
touch /opt/anime-new/backend/storage/logs/daemon.log
chown -R www-data:www-data /opt/anime-new/backend/storage
chmod 775 /opt/anime-new/backend/storage/logs

echo "### systemd 服务"
cat > /etc/systemd/system/anime-daemon.service <<'EOF'
[Unit]
Description=Anime New background daemon (RSS fetch / qB sync / mail queue)
After=network-online.target mysql.service
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/opt/anime-new/backend
ExecStart=/usr/bin/php /opt/anime-new/backend/bin/daemon.php
Restart=always
RestartSec=10
StandardOutput=append:/opt/anime-new/backend/storage/logs/daemon.log
StandardError=append:/opt/anime-new/backend/storage/logs/daemon.log
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now anime-daemon
sleep 3
echo "daemon: $(systemctl is-active anime-daemon)"
echo "--- 启动日志 ---"
tail -3 /opt/anime-new/backend/storage/logs/daemon.log 2>/dev/null || echo "(暂无日志)"
echo "### DAEMON_DONE"
