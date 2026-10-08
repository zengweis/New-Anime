"""位图字幕图片叠加：登记 + 资产路径校验 + HTTP 取图。"""
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


sftp = c.open_sftp()
sftp.put(os.path.join(os.path.dirname(__file__), '_check_tracks.php'), '/tmp/_check_tracks.php')
sftp.close()

print('=== 语法 ===')
print(sh('cd /opt/anime-new/backend && php -l src/Service/SubtitleService.php 2>&1 | tail -1'))

print()
print('=== 回收登记（run 0）===')
print(sh('cd /opt/anime-new/backend && php bin/ocr_subs.php run 0 2>&1'))
print()
print('=== 再跑一次（应 0 登记，验证幂等）===')
print(sh('cd /opt/anime-new/backend && php bin/ocr_subs.php run 0 2>&1'))

print()
print('=== 字幕轨 ===')
print(sh('cd /opt/anime-new/backend && php /tmp/_check_tracks.php 80 2>&1'))

print()
print('=== 登录取令牌 ===')
tok = sh("curl -s -m 10 -k -X POST https://127.0.0.1/api/auth/login -H 'Content-Type: application/json'"
         " -d '{\"account\":\"zeng\",\"password\":\"<password>\"}'"
         " | python3 -c \"import sys,json;print(json.load(sys.stdin)['data']['token'])\"")
print('  token:', tok[:16] + '…')

print()
print('=== HTTP 取清单与图片（未加 nginx 新 location 前会 404）===')
for path in ['/subtitle/80/1/manifest.json', '/subtitle/80/1/00001.webp', '/subtitle/80/0.vtt']:
    print(sh(f"curl -s -o /dev/null -m 15 -k -w '%{{http_code}} %{{size_download}}B %{{content_type}}' "
             f"'https://127.0.0.1{path}?token={tok}'"))
    print('   ', path)

c.close()
