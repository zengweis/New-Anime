"""给线上 nginx 加图片字幕路由并 reload。

转义教训：把含正则的片段塞进「本地 python → 远端 python」两层字符串里，
\\d 之类会被吃掉一层，正则静默失配。改成把块内容单独传文件，远端只做按行匹配。
"""
import os
import sys

os.environ.setdefault('AN_HOST', '<server-ip>')
os.environ.setdefault('AN_PASS', '<server-password>')
os.environ.setdefault('AN_RETRIES', '1')
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', '..', '..', 'anime-new', 'tools'))

from sshconn import connect  # noqa: E402

BLOCK = """
# 图片字幕：时间清单 + 逐屏图片（由 <img src> 发起，令牌同样只能走 query）
location ~ ^/subtitle/(\\d+)/(\\d+)/(manifest\\.json|[0-9]{3,6}\\.(?:webp|png|jpg))$ {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.5-fpm.sock;
    fastcgi_param SCRIPT_FILENAME /opt/anime-new/backend/public/subtitle.php;
    fastcgi_param SCRIPT_NAME     /subtitle.php;
    fastcgi_param QUERY_STRING    id=$1&track=$2&file=$3&$args;
    fastcgi_read_timeout 60s;
    fastcgi_buffering on;
}
"""

PATCH = '''
import glob

block = open('/tmp/_nginx_block.txt', encoding='utf-8').read()
if not block.endswith(chr(10)):
    block += chr(10)

for p in sorted(glob.glob('/etc/nginx/**/*.conf', recursive=True)):
    try:
        text = open(p, encoding='utf-8', errors='replace').read()
    except Exception:
        continue
    if 'subtitle' not in text or '.vtt' not in text:
        continue
    if 'manifest' in text and 'file=$3' in text:
        print('already patched:', p)
        continue

    lines = text.split(chr(10))
    start = None
    for i, l in enumerate(lines):
        if 'location ~ ^/subtitle/' in l and '.vtt' in l:
            start = i
            break
    if start is None:
        print('!! 没找到 vtt location:', p)
        continue

    depth = 0
    end = None
    for i in range(start, len(lines)):
        depth += lines[i].count('{') - lines[i].count('}')
        if i > start and depth <= 0:
            end = i
            break
    if end is None:
        print('!! 括号没配对:', p)
        continue

    new = chr(10).join(lines[: end + 1]) + chr(10) + block + chr(10).join(lines[end + 1 :])
    open(p, 'w', encoding='utf-8').write(new)
    print('patched:', p, '(行 %d-%d 之后)' % (start + 1, end + 1))
'''

c = connect()


def sh(cmd, t=300):
    _, o, e = c.exec_command(cmd, timeout=t)
    return (o.read().decode('utf-8', 'replace') + e.read().decode('utf-8', 'replace')).strip()


sftp = c.open_sftp()
with sftp.open('/tmp/_nginx_block.txt', 'w') as f:
    f.write(BLOCK)
with sftp.open('/tmp/_patch_nginx.py', 'w') as f:
    f.write(PATCH)
sftp.close()

print('=== 打补丁 ===')
print(sh('python3 /tmp/_patch_nginx.py 2>&1'))
print()
print('=== nginx -t ===')
print(sh('nginx -t 2>&1'))
print()
print('=== reload ===')
print(sh('systemctl reload nginx && sleep 1 && systemctl is-active nginx'))
print()
print('=== 生效后的路由 ===')
print(sh("grep -n -A3 'manifest' /etc/nginx/snippets/anime-new-app.conf | head -12"))

c.close()
