#!/usr/bin/env bash
# PGS（位图字幕）OCR 可行性探测：抽 sup → OCR → 打印质量与耗时
#
#   bash tools/pgs_ocr_probe.sh <mkv路径> <流索引> <语言> [输出名]
#
# 为什么要单独跑一遍探测：
#   OCR 的质量和耗时决定「值不值得把它接进生产流水线」，光看文档判断不了。
#   这里把中间产物留在 /tmp/ocr 里，便于人工核对识别效果。

set -u

F="${1:?用法: pgs_ocr_probe.sh <mkv> <stream_index> <lang> [name]}"
IDX="${2:?缺 stream index}"
LANG="${3:-zh}"
NAME="${4:-probe}"

WORK=/tmp/ocr
mkdir -p "$WORK"
rm -f "$WORK/$NAME.sup" "$WORK/$NAME.srt"

export TESSDATA_PREFIX=/usr/share/tesseract-ocr/5/tessdata

echo "== 1. ffmpeg 抽取 PGS（-c:s copy，不做任何转码）=="
t0=$(date +%s)
if ! ffmpeg -y -v error -i "$F" -map "0:$IDX" -c:s copy -f sup "$WORK/$NAME.sup"; then
  echo "  抽取失败"
  exit 1
fi
t1=$(date +%s)
ls -la "$WORK/$NAME.sup" | awk '{print "  sup 大小:", $5, "字节"}'
echo "  抽取耗时: $((t1 - t0))s"

echo
echo "== 2. pgsrip OCR（tesseract $LANG）=="
t2=$(date +%s)
/opt/anime-new/venv-ocr/bin/pgsrip rip "$WORK/$NAME.sup" \
  -l "$LANG" --no-tessdata-download --keep-temp-files \
  > "$WORK/$NAME.log" 2>&1
rc=$?
t3=$(date +%s)
echo "  退出码: $rc   耗时: $((t3 - t2))s"

if [ ! -f "$WORK/$NAME.srt" ]; then
  echo "  未生成 srt，日志末尾："
  tail -15 "$WORK/$NAME.log"
  exit 1
fi

echo
echo "== 3. 结果统计 =="
CUES=$(grep -c '^[0-9]*$' "$WORK/$NAME.srt" || true)
echo "  字幕条数: $CUES"
echo "  srt 大小: $(wc -c < "$WORK/$NAME.srt") 字节"
echo "  PNG 帧数: $(ls "$WORK"/*.png 2>/dev/null | wc -l)"

echo
echo "== 4. 前 20 行（人工核对识别质量）=="
head -20 "$WORK/$NAME.srt"

echo
echo "== 5. 随机中段 20 行 =="
sed -n '200,220p' "$WORK/$NAME.srt"
