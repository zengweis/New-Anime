#!/usr/bin/env bash
# 验证 pause/resume 兜底真的能改变 qB 状态。
#
# 必须先把自动暂停关掉再测：否则 daemon 每 60 秒会把「已完成且在做种」的任务重新暂停，
# 看起来就像 resume 无效 —— 这是个很容易误判的交互。
Q=http://127.0.0.1:8080/api/v2

curl -s -X POST "$Q/auth/login" --data-urlencode username=admin \
     --data-urlencode password=AnQb_000lr9GBz3pD -c /tmp/qbck -o /dev/null

H=$(curl -s -b /tmp/qbck "$Q/torrents/info?category=anime" \
    | python3 -c "import sys,json;print(json.load(sys.stdin)[0]['hash'])")

st() {
  curl -s -b /tmp/qbck "$Q/torrents/info?category=anime" \
    | python3 -c "import sys,json;d=json.load(sys.stdin);print(' '.join(sorted(set(t['state'] for t in d))))"
}

TOKEN=$(curl -s -k -X POST https://127.0.0.1/api/auth/login \
        -H 'Content-Type: application/json' \
        -d '{"account":"zeng","password":"<password>"}' \
        | python3 -c "import sys,json;print(json.load(sys.stdin)['data']['token'])")

act() {  # $1 = pause|resume
  curl -s -k -m 20 -X POST https://127.0.0.1/api/admin/torrents/action \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
    -d "{\"action\":\"$1\",\"hashes\":[\"$H\"]}"
  echo
}

echo "临时关闭自动暂停（否则 resume 会被 daemon 立刻按回去）"
mysql anime_new -e "UPDATE setting SET v='0' WHERE k='qb_pause_after_done';"
sleep 1

echo "初始状态            : $(st)"

echo -n "后端 act pause  -> "; act pause
sleep 3
echo "  暂停后状态        : $(st)"

echo -n "后端 act resume -> "; act resume
sleep 5
echo "  恢复后状态        : $(st)"

echo "恢复自动暂停开关"
mysql anime_new -e "UPDATE setting SET v='1' WHERE k='qb_pause_after_done';"
echo -n "  等 daemon 按回去（最多 70s）"
for i in $(seq 1 14); do
  sleep 5
  s=$(st)
  if [ "$s" = "pausedUP" ]; then
    echo
    echo "  已被自动暂停: $s  ✓（自动暂停链路正常）"
    break
  fi
  echo -n "."
done
