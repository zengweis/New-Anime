"""弹幕功能端到端验收。

分四段：
  1 读接口（ETag / 304 / gzip / 空表）
  2 写入校验与三条限速（dup / 超长 / 时间越界 / burst）
  3 后台：列表、导入、批量删除、恢复、越权
  4 灌入一份真实规模的种子弹幕（好让播放页能看出效果），跑全量回归
"""
import json
import os
import sys
import time

os.environ.setdefault('AN_HOST', '<server-ip>')
os.environ.setdefault('AN_PASS', '<server-password>')
os.environ.setdefault('AN_RETRIES', '1')
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', '..', '..', 'anime-new', 'tools'))

from sshconn import connect  # noqa: E402

c = connect()
EP = 80


def sh(cmd, t=600):
    _, o, e = c.exec_command(cmd, timeout=t)
    return (o.read().decode('utf-8', 'replace') + e.read().decode('utf-8', 'replace')).strip()


def curl(path, extra='', method='GET', body=None, token=None):
    args = ['curl', '-s', '-m', '20', '-k', '-X', method]
    if token:
        args += ['-H', '"Authorization: Bearer %s"' % token]
    if body is not None:
        args += ['-H', '"Content-Type: application/json"', '-d', "'%s'" % json.dumps(body, ensure_ascii=False)]
    args += extra.split() if extra else []
    args.append('"https://127.0.0.1%s"' % path)
    return sh(' '.join(args))


BASE = 'https://127.0.0.1'
tok = sh("curl -s -m 10 -k -X POST %s/api/auth/login -H 'Content-Type: application/json'"
         " -d '{\"account\":\"zeng\",\"password\":\"<password>\"}'"
         " | python3 -c \"import sys,json;print(json.load(sys.stdin)['data']['token'])\"" % BASE)

print('=== 1. 读接口 ===')
hdr = sh('curl -s -D - -o /dev/null -m 20 -k "%s/api/episodes/%d/danmaku"' % (BASE, EP))
print('  状态行/关键头:')
for l in hdr.splitlines():
    if any(k in l.lower() for k in ['http/', 'etag', 'cache-control', 'content-type']):
        print('   ', l.strip())
etag = ''
for l in hdr.splitlines():
    if l.lower().startswith('etag'):
        etag = l.split(':', 1)[1].strip()

plain = sh('curl -s -m 20 -k "%s/api/episodes/%d/danmaku" -o /tmp/dm_plain.json -w "%%{size_download}"' % (BASE, EP))
gzipped = sh('curl -s -m 20 -k -H "Accept-Encoding: gzip" "%s/api/episodes/%d/danmaku" -o /tmp/dm_gz.bin -w "%%{size_download}" -D /tmp/dm_gz.hdr' % (BASE, EP))
enc = sh("grep -i content-encoding /tmp/dm_gz.hdr | tr -d '\\r'")
print('  未压缩: %s B   gzip: %s B   %s' % (plain, gzipped, enc))
code = sh('curl -s -o /dev/null -m 20 -k -w "%%{http_code}" -H "If-None-Match: %s" "%s/api/episodes/%d/danmaku"' % (etag, BASE, EP))
print('  带 If-None-Match 重取 → HTTP', code, '（期望 304）')

print()
print('=== 2. 写入与限速 ===')


def send(text, t_ms=30000, tok=None):
    r = curl('/api/episodes/%d/danmaku' % EP, method='POST',
             body={'t_ms': t_ms, 'text': text, 'mode': 1, 'color': 0xFFFFFF, 'size': 1},
             token=tok if tok is not None else tok0)
    try:
        return json.loads(r)
    except Exception:
        return {'raw': r[:200]}


tok0 = tok
tests = []
r = send('验收测试·第一条')
tests.append(('正常发送', r.get('code') == 0, r.get('msg')))
send('验收测试·第一条')  # 故意重复
r = send('验收测试·第一条')
tests.append(('同文本 30 秒内重复被拒', r.get('code') != 0, r.get('msg')))
r = send('x' * 130)
tests.append(('超长文本被拒', r.get('code') != 0, r.get('msg')))
r = send('时间越界', t_ms=99999999)
tests.append(('时间越界被拒', r.get('code') != 0, r.get('msg')))
burst_ok = 0
for i in range(4):
    r = send('验收测试·连发%d' % i)
    if r.get('code') == 0:
        burst_ok += 1
r = send('验收测试·第6条')
tests.append(('10 秒内第 6 条被限速', r.get('code') != 0, r.get('msg')))
print('  连发 4 条成功 %d 条（前 5 条配额内）' % burst_ok)
for name, ok, msg in tests:
    print('  [%s] %-26s %s' % ('OK' if ok else 'FAIL', name, msg))

r = curl('/api/episodes/%d/danmaku' % EP, method='POST', body={'t_ms': 1000, 'text': '未登录'})
print('  未登录发送 → HTTP', sh('curl -s -o /dev/null -m 20 -k -w "%%{http_code}" -X POST'
                              ' -H "Content-Type: application/json" -d \'{"t_ms":1000,"text":"x"}\''
                              ' "%s/api/episodes/%d/danmaku"' % (BASE, EP)), '（期望 401）')

print()
print('=== 3. 后台 ===')
lst = json.loads(sh('curl -s -m 20 -k -H "Authorization: Bearer %s" "%s/api/admin/danmaku?episode_id=%d&per=100"'
                    % (tok, BASE, EP)))
rows = lst.get('data', {}).get('list', [])
print('  admin 列表: total=%s 返回 %d 条' % (lst.get('data', {}).get('total'), len(rows)))
if rows:
    s = rows[-1]
    print('  样本: 时间 %sms · 用户 %s(#%s) · 来源 %s' % (s['t_ms'], s['username'], s['user_id'], s['source']))

xml = ('<?xml version="1.0" encoding="UTF-8"?><i><chatserver>chat.bilibili.com</chatserver>'
       '<d p="12.5,1,25,16777215,1700000000,0,abc,1">导入·滚动一条</d>'
       '<d p="30.0,5,25,16711680,1700000001,0,abc,2">导入·顶部红色</d>'
       '<d p="45.5,4,18,16776960,1700000002,0,abc,3">导入·底部小字</d></i>')
imp = json.loads(sh('curl -s -m 30 -k -H "Authorization: Bearer %s" -H "Content-Type: application/json"'
                    ' -X POST -d \'%s\' "%s/api/admin/danmaku/import"'
                    % (tok, json.dumps({'episode_id': EP, 'text': xml}, ensure_ascii=False), BASE)))
print('  API 导入 XML:', imp.get('msg'), imp.get('data'))

ids = [r['id'] for r in json.loads(sh('curl -s -m 20 -k -H "Authorization: Bearer %s" "%s/api/admin/danmaku?episode_id=%d&per=200"'
                                      % (tok, BASE, EP)))['data']['list']
       if r['source'] == 'web']
if ids:
    d = json.loads(sh('curl -s -m 20 -k -H "Authorization: Bearer %s" -H "Content-Type: application/json" -X POST'
                      ' -d \'%s\' "%s/api/admin/danmaku/delete"' % (tok, json.dumps({'ids': ids}), BASE)))
    print('  批量删除验收数据:', d.get('msg'))
    rs = json.loads(sh('curl -s -m 20 -k -H "Authorization: Bearer %s" -H "Content-Type: application/json" -X POST'
                       ' -d \'%s\' "%s/api/admin/danmaku/restore"' % (tok, json.dumps({'ids': ids[:1]}), BASE)))
    print('  恢复 1 条（验证可撤销）:', rs.get('msg'))
    json.loads(sh('curl -s -m 20 -k -H "Authorization: Bearer %s" -H "Content-Type: application/json" -X POST'
                  ' -d \'%s\' "%s/api/admin/danmaku/delete"' % (tok, json.dumps({'ids': ids[:1]}), BASE)))

print()
print('=== 4. CLI 导入一份真实规模的种子弹幕 ===')
gen = r'''
import random
random.seed(80)
lines = ["前方高能","这就来了","经典重现","画质真不错","这段我看了十遍","BGM 神了","注意看左下角",
         "这里有个伏笔","笑死我了","第一次看的时候没注意","弹幕护体","泪目","这作画太顶了",
         "柯南永远的神","名场面预警","bgm 一起就哭了","细节满分","这句话是重点","两年后再看懂了",
         "压迫感拉满","这转场绝了","小兰好温柔","灰原哀！","博士又整活","真相只有一个"]
mode_w = [1]*18 + [5]*1 + [4]*1
out = ['<?xml version="1.0" encoding="UTF-8"?><i>']
t = 20.0
while t < 1440.0:
    n = random.randint(0, 2)
    for _ in range(n):
        m = random.choice(mode_w)
        size = random.choice([18, 25, 25, 25])
        color = random.choice([16777215]*8 + [16711680, 16776960, 65280, 65535])
        out.append('<d p="%.2f,%d,%d,%d,1700000000,0,seed,1">%s</d>'
                   % (t + random.random(), m, size, color, random.choice(lines)))
    t += 4.0
out.append('</i>')
open('/tmp/seed_dm.xml','w',encoding='utf-8').write('\n'.join(out))
print('生成 %d 行' % (len(out) - 2))
'''
sftp = c.open_sftp()
with sftp.open('/tmp/_gen_seed.py', 'w') as f:
    f.write(gen)
sftp.close()
print(sh('python3 /tmp/_gen_seed.py'))
print(sh('cd /opt/anime-new/backend && php bin/danmaku_import.php %d /tmp/seed_dm.xml 2>&1' % EP))
print()
print(sh('cd /opt/anime-new/backend && php bin/danmaku_import.php --stats 2>&1'))

print()
print('=== 数值确认 ===')
print(sh('''mysql -uroot anime_new -e "SELECT status, source, COUNT(*) n, MIN(t_ms) t0, MAX(t_ms) t1,
  SUM(mode=1) roll, SUM(mode=4) bottom, SUM(mode=5) top FROM danmaku WHERE episode_id=%d GROUP BY status, source" 2>&1''' % EP))
plain2 = sh('curl -s -m 20 -k "%s/api/episodes/%d/danmaku" -o /tmp/dm2.json -w "%%{size_download}"' % (BASE, EP))
gzip2 = sh('curl -s -m 20 -k -H "Accept-Encoding: gzip" "%s/api/episodes/%d/danmaku" -o /dev/null -w "%%{size_download}"' % (BASE, EP))
total = sh('python3 -c "import json;print(json.load(open(\'/tmp/dm2.json\'))[\'data\'][\'total\'])"')
print('  读接口返回 %s 条：未压缩 %s B → gzip %s B（压缩比 %.1fx）'
      % (total, plain2, gzip2, int(plain2) / max(1, int(gzip2))))

print()
print('=== 回归 ===')
print(sh('cd /opt/anime-new/backend && php bin/e2e.php https://127.0.0.1 2>&1 | tail -3'))
print(sh('cd /opt/anime-new/backend && php bin/e2e_s3_live.php https://127.0.0.1 2>&1 | tail -3'))
c.close()
