"""清理验收产生的测试弹幕行，并确认最终状态。"""
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


def sh(cmd, t=200):
    _, o, e = c.exec_command(cmd, timeout=t)
    return (o.read().decode('utf-8', 'replace') + e.read().decode('utf-8', 'replace')).strip()


print('=== 清理前 ===')
print(sh("""mysql -uroot anime_new -e "SELECT source, status, COUNT(*) n FROM danmaku GROUP BY source, status" 2>&1"""))

print()
print('=== 删除验收痕迹（source=web 的那几条是测试数据，不是用户数据）===')
sh("""mysql -uroot anime_new -e "DELETE FROM danmaku WHERE source = 'web'" 2>&1""")
print('已删除')

print()
print('=== 清理后 ===')
print(sh("""mysql -uroot anime_new -e "SELECT source, status, COUNT(*) n, MIN(t_ms) t0, MAX(t_ms) t1
  FROM danmaku GROUP BY source, status" 2>&1"""))

tok = sh("curl -s -m 10 -k -X POST %s/api/auth/login -H 'Content-Type: application/json'"
         " -d '{\"account\":\"zeng\",\"password\":\"<password>\"}'"
         " | python3 -c \"import sys,json;print(json.load(sys.stdin)['data']['token'])\"" % B)

r = json.loads(sh('curl -s -m 20 -k %s/api/episodes/80/danmaku' % B))
print('播放页读到        :', r['data']['total'], '条')
a = json.loads(sh('curl -s -m 20 -k -H "Authorization: Bearer %s" "%s/api/admin/danmaku?episode_id=80&per=3"'
                  % (tok, B)))
print('后台列表（正常）  : total=%s 样本=%s' % (
    a['data']['total'], [x['text'] for x in a['data']['list']]))
d = json.loads(sh('curl -s -m 20 -k -H "Authorization: Bearer %s" "%s/api/admin/danmaku?episode_id=80&status=9"'
                  % (tok, B)))
print('后台列表（已删除）:', d['data']['total'], '条（应为 0，测试行已整行清掉）')
c.close()
