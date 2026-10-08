"""上线后最终确认：负载结构、页面、产物、服务、体积。"""
import os
import sys

os.environ.setdefault('AN_HOST', '<server-ip>')
os.environ.setdefault('AN_PASS', '<server-password>')
os.environ.setdefault('AN_RETRIES', '1')
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', '..', '..', 'anime-new', 'tools'))

from sshconn import connect  # noqa: E402

c = connect()


def sh(cmd, t=200):
    _, o, e = c.exec_command(cmd, timeout=t)
    return (o.read().decode('utf-8', 'replace') + e.read().decode('utf-8', 'replace')).strip()


PROBE = r'''
import json, collections
d = json.load(open('/tmp/dm.json'))['data']
print(' total:', d['total'])
print(' 首条:', d['items'][0])
print(' 末条:', d['items'][-1])
print(' mode 分布:', dict(collections.Counter(i[1] for i in d['items'])))
print(' size 分布:', dict(collections.Counter(i[3] for i in d['items'])))
print(' 时间范围: %.1fs ~ %.1fs' % (d['items'][0][0] / 1000, d['items'][-1][0] / 1000))
print(' 颜色种类:', len(set(i[2] for i in d['items'])))
print(' 字段数一致:', set(len(i) for i in d['items']))
'''

sftp = c.open_sftp()
with sftp.open('/tmp/_probe.py', 'w') as f:
    f.write(PROBE)
sftp.close()

print('=== 接口负载结构（前端按 [t,mode,color,size,text] 下标读）===')
sh("curl -s -m 20 -k --compressed https://127.0.0.1/api/episodes/80/danmaku -o /tmp/dm.json")
print(sh('python3 /tmp/_probe.py'))

print()
print('=== 页面 ===')
for p in ['/play/80', '/admin/danmaku', '/']:
    print(' ', sh("curl -s -o /dev/null -m 15 -k -w '%{http_code} %{size_download}B' https://www.acgn.help" + p), p)

idx = sh('curl -s -m 15 -k https://www.acgn.help/ | grep -o "/assets/[a-zA-Z0-9._-]*" | sort -u')
urls = [u for u in idx.splitlines() if u]
bad = []
for u in urls:
    code = sh("curl -s -o /dev/null -m 15 -k -w '%{http_code}' https://www.acgn.help" + u)
    if code != '200':
        bad.append((code, u))
print('  index.html 引用 %d 个资源，非 200 的 %d 个 %s' % (len(urls), len(bad), bad if bad else ''))

print()
print('=== 服务状态 ===')
print(sh('for s in nginx php8.5-fpm mysql anime-daemon qbittorrent-nox@root certbot.timer; do '
         'printf "%-22s %s/%s\n" $s $(systemctl is-active $s) $(systemctl is-enabled $s); done'))

print()
print('=== 弹幕表体积与分布 ===')
print(sh("""mysql -uroot anime_new -e "SELECT source, status, COUNT(*) n FROM danmaku GROUP BY source, status" 2>&1"""))
print(sh("""mysql -uroot -e "SELECT table_name, ROUND((data_length+index_length)/1024,1) KB FROM information_schema.tables
  WHERE table_schema='anime_new' AND table_name='danmaku'" 2>&1 | tail -1"""))

c.close()
