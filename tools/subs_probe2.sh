set -u
F="/data/downloads/anime/[LoliHouse] Yani Neko - 10 [WebRip 1080p HEVC-10bit AAC SRTx2].mkv"
echo "=== 机器规格 ==="
echo -n "CPU 核数: "; nproc
free -m | head -2
echo
echo "=== 文件与时长 ==="
ls -lh "$F" | awk '{print $5, $9}'
ffprobe -v error -show_entries format=duration,bit_rate -of default=nw=1 "$F"
echo
echo "=== 连续三次抽取计时（看是否稳定）==="
for i in 1 2 3; do
  /usr/bin/time -f "第 $i 次: %e 秒, CPU占用 %P" ffmpeg -v error -i "$F" -map 0:s:0 -c:s webvtt /tmp/s_$i.vtt -y 2>&1 | tail -1
done
echo
echo "=== 冷读吞吐（整文件读一遍，测磁盘）==="
sync
/usr/bin/time -f "读完整 1GB: %e 秒" dd if="$F" of=/dev/null bs=1M 2>&1 | tail -1
echo
echo "=== 重测（应更快，若命中页缓存）==="
ffmpeg -v error -i "$F" -map 0:s:0 -c:s webvtt /tmp/s_4.vtt -y
start=$(date +%s.%N); ffmpeg -v error -i "$F" -map 0:s:0 -c:s webvtt /tmp/s_5.vtt -y; end=$(date +%s.%N)
echo "第二次: $(echo "$end - $start" | bc) 秒"
echo
echo "=== 字幕完整性：末尾 8 行 ==="
tail -8 /tmp/s_1.vtt
echo
echo "=== 字幕条目数 ==="
grep -c ' --> ' /tmp/s_1.vtt
