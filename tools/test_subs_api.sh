set -u
B=http://127.0.0.1

echo "=== 登录取令牌 ==="
RESP=$(curl -s -X POST "$B/api/auth/login" -H 'Content-Type: application/json' \
  -d '{"username":"zeng","password":"<password>"}')
TOKEN=$(printf '%s' "$RESP" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"]["token"] ?? "";')
echo "令牌长度: ${#TOKEN}"
if [ -z "$TOKEN" ]; then echo "登录响应: $RESP"; exit 1; fi

echo
echo "=== 单集接口 ==="
curl -s "$B/api/episodes/25" -H "Authorization: Bearer $TOKEN" | php -r '
$d=json_decode(stream_get_contents(STDIN),true);
$e=$d["data"]["episode"] ?? [];
printf("  playback.mode : %s\n", $e["playback"]["mode"] ?? "-");
printf("  编码          : %s / %s\n", $e["vcodec"] ?? "-", $e["pix_fmt"] ?? "-");
printf("  sub_tracks    : %s\n", $e["sub_tracks"] ?? "-");
printf("  subtitle_state: %s\n", json_encode($e["subtitle_state"] ?? null, JSON_UNESCAPED_UNICODE));
printf("  subtitles     : %s\n", json_encode($e["subtitles"] ?? null, JSON_UNESCAPED_UNICODE));
'

echo
echo "=== GET /api/episodes/25/subtitles ==="
curl -s "$B/api/episodes/25/subtitles" -H "Authorization: Bearer $TOKEN" | head -c 700; echo

echo
echo "=== VTT 分发 ==="
curl -s -D - -o /tmp/dl0.vtt "$B/subtitle/25/0.vtt?token=$TOKEN" | grep -Ei "HTTP/|content-type|content-length|etag|cache-control"
echo "  下载字节: $(wc -c < /tmp/dl0.vtt)"
echo "  首行: $(head -1 /tmp/dl0.vtt)"
echo "  末条时间: $(grep ' --> ' /tmp/dl0.vtt | tail -1)"

echo
echo "=== 条件请求应 304 ==="
ETAG=$(curl -s -D - -o /dev/null "$B/subtitle/25/0.vtt?token=$TOKEN" | grep -i '^etag:' | tr -d '\r' | cut -d' ' -f2)
curl -s -o /dev/null -w "  If-None-Match: HTTP %{http_code}\n" -H "If-None-Match: $ETAG" "$B/subtitle/25/0.vtt?token=$TOKEN"

echo
echo "=== 鉴权边界 ==="
curl -s -o /dev/null -w "  无令牌     : HTTP %{http_code}\n" "$B/subtitle/25/0.vtt"
curl -s -o /dev/null -w "  错令牌     : HTTP %{http_code}\n" "$B/subtitle/25/0.vtt?token=bad"
curl -s -o /dev/null -w "  不存在的轨 : HTTP %{http_code}\n" "$B/subtitle/25/9.vtt?token=$TOKEN"
curl -s -o /dev/null -w "  匿名抽字幕 : HTTP %{http_code}\n" -X POST "$B/api/episodes/25/subtitles/extract"

echo
echo "=== 按需抽取（应命中缓存）==="
curl -s -X POST "$B/api/episodes/25/subtitles/extract" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{}' | head -c 600; echo

echo
echo "=== 下载模式（Content-Disposition）==="
curl -s -D - -o /dev/null "$B/subtitle/25/1.vtt?token=$TOKEN&dl=1" | grep -i "content-disposition"

echo
echo "=== 播放计数（应为 1，不被 Range 放大）==="
mysql anime_new -e "SELECT id, views FROM episode WHERE id=25" 2>&1
mysql anime_new -e "SELECT user_id, episode_id, counted_at FROM play_view" 2>&1
