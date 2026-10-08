#!/usr/bin/env bash
# qBittorrent pause/resume 端点兼容性验证
# 结论要能回答：这个 qB 构建到底认哪一对端点，后端兜底逻辑是否覆盖
Q=http://127.0.0.1:8080/api/v2
curl -s -X POST "$Q/auth/login" --data-urlencode username=admin \
     --data-urlencode password=AnQb_000lr9GBz3pD -c /tmp/qbck -o /dev/null

H=$(curl -s -b /tmp/qbck "$Q/torrents/info?category=anime" \
    | python3 -c "import sys,json;print(json.load(sys.stdin)[0]['hash'])")

st() {
  curl -s -b /tmp/qbck "$Q/torrents/info?category=anime" \
    | python3 -c "import sys,json;d=json.load(sys.stdin);print(' '.join(sorted(set(t['state'] for t in d))))"
}

probe() {  # $1=端点名
  printf '  %-22s -> HTTP ' "$1"
  curl -s -o /dev/null -w '%{http_code}\n' -b /tmp/qbck -X POST "$Q/torrents/$1" --data-urlencode "hashes=$H"
}

echo "hash          : $H"
echo "初始状态      : $(st)"
echo "--- 逐端点探测 ---"
for ep in stop pause start resume; do probe "$ep"; done
echo "--- 探测后状态 : $(st)"
echo "--- 用后端接口恢复（验证 resume 兜底真的能恢复）---"
echo "  先确保处于暂停："
probe pause > /dev/null
echo "    暂停后状态 : $(st)"

TOKEN=$(curl -s -k -X POST https://127.0.0.1/api/auth/login \
        -H 'Content-Type: application/json' \
        -d '{"account":"zeng","password":"<password>"}' \
        | python3 -c "import sys,json;print(json.load(sys.stdin)['data']['token'])")

curl -s -k -m 20 -X POST https://127.0.0.1/api/admin/torrents/action \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d "{\"action\":\"resume\",\"hashes\":[\"$H\"]}"
echo
echo "    经后端 resume 后状态 : $(st)"
