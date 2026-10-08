"""精确验证 ETag 指纹是否覆盖「删除」这类变化。"""
import json
import os
import sys

os.environ.setdefault('AN_HOST', '<server-ip>')
os.environ.setdefault('AN_PASS', '<server-password>')
os.environ.setdefault('AN_RETRIES', '1')
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', '..', '..', 'anime-new', 'tools'))

from sshconn import connect  # noqa: E402

c = connect()
B = 'https://127.0.0.1'
EP = 80


def sh(cmd, t=200):
    _, o, e = c.exec_command(cmd, timeout=t)
    return (o.read().decode('utf-8', 'replace') + e.read().decode('utf-8', 'replace')).strip()


tok = sh("curl -s -m 10 -k -X POST %s/api/auth/login -H 'Content-Type: application/json'"
         " -d '{\"account\":\"zeng\",\"password\":\"<password>\"}'"
         " | python3 -c \"import sys,json;print(json.load(sys.stdin)['data']['token'])\"" % B)


def etag_of():
    return sh("curl -s -D - -o /dev/null -m 20 -k %s/api/episodes/%d/danmaku"
              " | grep -i '^etag' | sed -e 's/^[Ee]tag: //' -e 's/\\r//'" % (B, EP))


def hit(etag):
    return sh("curl -s -o /dev/null -m 20 -k -w '%%{http_code}' -H 'If-None-Match: %s'"
              " %s/api/episodes/%d/danmaku" % (etag, B, EP))


def api_post(path, body):
    return json.loads(sh("curl -s -m 20 -k -H 'Authorization: Bearer %s' -H 'Content-Type: application/json'"
                         " -X POST -d '%s' %s%s" % (tok, json.dumps(body), B, path)))


e0 = etag_of()
print('初始 ETag      :', e0, '重取 →', hit(e0), '（期望 304）')

# 找一条可见弹幕
rows = json.loads(sh("curl -s -m 20 -k -H 'Authorization: Bearer %s' '%s/api/admin/danmaku?episode_id=%d&per=1'"
                     % (tok, B, EP)))['data']['list']
vid = rows[0]['id']
print('拿一条可见弹幕   : id=%d  %s' % (vid, rows[0]['text'][:20]))

print('删除它          :', api_post('/api/admin/danmaku/delete', {'ids': [vid]}).get('msg'))
e1 = etag_of()
print('新 ETag        :', e1, '用旧 ETag 重取 →', hit(e0), '（期望 200：指纹必须变了）')

print('恢复它          :', api_post('/api/admin/danmaku/restore', {'ids': [vid]}).get('msg'))
e2 = etag_of()
print('恢复后 ETag    :', e2, '用最初的 ETag 重取 →', hit(e0), '（期望 304：回到同一状态）')

print()
print('数据完整性      :', sh("""mysql -uroot anime_new -e "SELECT SUM(status=0) alive, SUM(status=9) dead, COUNT(*) total
  FROM danmaku WHERE episode_id=%d" 2>&1""" % EP))
c.close()
