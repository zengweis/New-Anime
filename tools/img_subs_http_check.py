"""图片字幕端到端 HTTP 验收：清单、图片、越权、VTT 都过一遍。"""
import json
import os
import sys

os.environ.setdefault('AN_HOST', '<server-ip>')
os.environ.setdefault('AN_PASS', '<server-password>')
os.environ.setdefault('AN_RETRIES', '1')
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', '..', '..', 'anime-new', 'tools'))

from sshconn import connect  # noqa: E402

c = connect()


def sh(cmd, t=300):
    _, o, e = c.exec_command(cmd, timeout=t)
    return (o.read().decode('utf-8', 'replace') + e.read().decode('utf-8', 'replace')).strip()


tok = sh("curl -s -m 10 -k -X POST https://127.0.0.1/api/auth/login -H 'Content-Type: application/json'"
         " -d '{\"account\":\"zeng\",\"password\":\"<password>\"}'"
         " | python3 -c \"import sys,json;print(json.load(sys.stdin)['data']['token'])\"")

print('=== 逐条请求 ===')
paths = [
    '/subtitle/80/1/manifest.json',
    '/subtitle/80/1/00001.webp',
    '/subtitle/80/1/00459.webp',
    '/subtitle/80/0.vtt',
    '/subtitle/80/1/../../../../etc/passwd',
    '/subtitle/80/1/nope.txt',
]
for p in paths:
    r = sh(f"curl -s -o /dev/null -m 15 -k -w '%{{http_code}}|%{{size_download}}|%{{content_type}}' "
           f"'https://127.0.0.1{p}?token={tok}'")
    print(f'  {r:40s}  {p}')

print()
print('=== 清单内容（前 3 条 cue）===')
raw = sh(f"curl -s -m 15 -k 'https://127.0.0.1/subtitle/80/1/manifest.json?token={tok}'")
try:
    d = json.loads(raw)
    print('  width/height:', d.get('width'), 'x', d.get('height'), '| count:', d.get('count'), '| format:', d.get('format'))
    for cue in d['cues'][:3]:
        print('  ', cue)
except Exception as e:
    print('  解析失败:', e, raw[:200])

print()
print('=== 图片真的是 webp 且非空 ===')
print(sh(f"curl -s -m 15 -k 'https://127.0.0.1/subtitle/80/1/00001.webp?token={tok}'"
         " -o /tmp/t.webp && file /tmp/t.webp && ls -la /tmp/t.webp"))

print()
print('=== 播放接口（episode 80）里的字幕清单 ===')
r = sh(f"curl -s -m 15 -k -H 'Authorization: Bearer {tok}' https://127.0.0.1/api/episodes/80 -o /tmp/ep80.json"
       " && python3 -c \"import json;d=json.load(open('/tmp/ep80.json'))['data'];"
       "e=d['episode'];print(' subtitles:', json.dumps(e.get('subtitles'), ensure_ascii=False));"
       "print(' playback:', {k: e['playback'][k] for k in ('mode','ext','storage') if k in e['playback']})\"")
print(r)

c.close()
