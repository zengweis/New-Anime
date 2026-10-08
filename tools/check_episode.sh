#!/usr/bin/env bash
# 临时诊断：登录拿 token，然后看 /api/episodes/25 的播放判定字段
set -u
BASE=http://127.0.0.1

LOGIN=$(curl -s -X POST "$BASE/api/auth/login" \
  -H 'Content-Type: application/json' \
  -d '{"account":"zeng","password":"<password>"}')

echo "=== 登录返回 ==="
echo "$LOGIN" | head -c 300
echo

TOKEN=$(echo "$LOGIN" | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
if [ -z "$TOKEN" ]; then
  # 兼容字段名不同的情况
  TOKEN=$(echo "$LOGIN" | grep -o '"token"[^,]*' | head -1 | cut -d'"' -f4)
fi
echo "token 长度: ${#TOKEN}"

echo
echo "=== /api/episodes/25 ==="
curl -s "$BASE/api/episodes/25" -H "Authorization: Bearer $TOKEN" \
  | python3 -c 'import sys,json;d=json.load(sys.stdin);e=d["data"]["episode"];print("HTTP code:",d["code"]);print(json.dumps({k:e[k] for k in ["id","title","ext","playable","vcodec","acodec","pix_fmt","sub_tracks","probe_at","playback"] if k in e},ensure_ascii=False,indent=2))'
