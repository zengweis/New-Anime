"""图片字幕上线后的全量回归。"""
import os
import sys

os.environ.setdefault('AN_HOST', '<server-ip>')
os.environ.setdefault('AN_PASS', '<server-password>')
os.environ.setdefault('AN_RETRIES', '1')
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', '..', '..', 'anime-new', 'tools'))

from sshconn import connect  # noqa: E402

c = connect()


def sh(cmd, t=900):
    _, o, e = c.exec_command(cmd, timeout=t)
    return (o.read().decode('utf-8', 'replace') + e.read().decode('utf-8', 'replace')).strip()


print('=== 重启 daemon（加载新代码）===')
print(sh('systemctl restart anime-daemon && sleep 3 && systemctl is-active anime-daemon'))
print(sh('grep "守护进程启动" /opt/anime-new/backend/storage/logs/daemon.log | tail -1'))

print()
print('=== 站点资源 ===')
for p in ['/play/80', '/admin/settings', '/subtitle/80/1/manifest.json']:
    print(sh(f"curl -s -o /dev/null -m 15 -k -w '%{{http_code}} %{{size_download}}B' https://www.acgn.help{p}", 60) or '', p)

print()
print('=== 全量回归 e2e.php ===')
print(sh('cd /opt/anime-new/backend && php bin/e2e.php https://127.0.0.1 2>&1 | tail -4'))

print()
print('=== 对象存储真实往返 ===')
print(sh('cd /opt/anime-new/backend && php bin/e2e_s3_live.php https://127.0.0.1 2>&1 | tail -4'))

print()
print('=== 位图字幕状态 ===')
print(sh('cd /opt/anime-new/backend && php bin/ocr_subs.php status 2>&1'))

print()
print('=== 服务状态 ===')
print(sh('for s in nginx php8.5-fpm mysql anime-daemon qbittorrent-nox@root certbot.timer; do '
         'printf "%-22s %s/%s\\n" $s $(systemctl is-active $s) $(systemctl is-enabled $s); done'))

print()
print('=== 磁盘 ===')
print(sh('df -h / | tail -1; du -sh /data/anime-subs; du -sh /data/anime-subs/80/img3'))

c.close()
