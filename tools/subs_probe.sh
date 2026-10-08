set -u
F="/data/downloads/anime/[LoliHouse] Yani Neko - 10 [WebRip 1080p HEVC-10bit AAC SRTx2].mkv"
echo "=== 机器规格 ==="
echo -n "CPU 核数: "; nproc
free -m | head -2
echo
echo "=== 片源 ==="
ls -lh "$F" | awk '{print $5, $9}'
echo
echo "=== 字幕流清单 ==="
ffprobe -v error -select_streams s -show_entries stream=index,codec_name:stream_tags=language,title -of json "$F"
echo
echo "=== 分轨抽取计时（仅解封装，不解视频码） ==="
rm -f /tmp/sub_0.vtt /tmp/sub_1.vtt
start=$(date +%s.%N)
ffmpeg -v error -i "$F" -map 0:s:0 -c:s webvtt /tmp/sub_0.vtt -map 0:s:1 -c:s webvtt /tmp/sub_1.vtt -y
rc=$?
end=$(date +%s.%N)
echo "退出码=$rc 耗时=$(echo "$end - $start" | bc) 秒"
ls -l /tmp/sub_0.vtt /tmp/sub_1.vtt 2>&1 | awk '{print $5, $9}'
echo
echo "=== sub_0 前 8 行 ==="
head -8 /tmp/sub_0.vtt
echo "=== sub_1 前 8 行 ==="
head -8 /tmp/sub_1.vtt
echo
echo "=== 库内规模 ==="
mysql anime_new -N -e "SELECT CONCAT('episode 总数: ', COUNT(*)) FROM episode;
SELECT CONCAT('已探测编码: ', COUNT(*)) FROM episode WHERE probe_at IS NOT NULL;
SELECT CONCAT('有文件路径: ', COUNT(*)) FROM episode WHERE file_path IS NOT NULL AND file_path <> '';
SELECT CONCAT('总时长(小时): ', ROUND(SUM(COALESCE(duration,0))/3600,1)) FROM episode;" 2>&1
echo
echo "=== 汇总统计 ==="
