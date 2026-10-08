set -u
B=/opt/anime-new/backend

echo "=== 1. 字幕缓存目录 ==="
mkdir -p /data/anime-subs
chown www-data:www-data /data/anime-subs
chmod 755 /data/anime-subs
ls -ld /data/anime-subs

echo
echo "=== 2. 修正属主 ==="
chown www-data:www-data "$B/bin/extract_subs.php" "$B/src/Service/SubtitleService.php" "$B/bin/daemon.php" \
                      "$B/src/Controller/AnimeController.php" "$B/public/index.php" "$B/public/subtitle.php"
chown -R www-data:www-data "$B/migrations"
echo done

echo
echo "=== 3. PHP 语法检查 ==="
cd "$B"
for f in src/Service/SubtitleService.php bin/extract_subs.php bin/daemon.php \
         src/Controller/AnimeController.php public/index.php public/subtitle.php; do
  php -l "$f" 2>&1 | tail -1
done

echo
echo "=== 4. ffmpeg 路径 ==="
ls -l /usr/bin/ffmpeg /usr/bin/ffprobe 2>&1 | awk '{print $1, $9}'

echo
echo "=== 5. 写入设置 ==="
mysql anime_new -e "
INSERT INTO setting (k,v) VALUES ('subtitle_root','/data/anime-subs') ON DUPLICATE KEY UPDATE v=VALUES(v);
INSERT INTO setting (k,v) VALUES ('ffmpeg_path','/usr/bin/ffmpeg')   ON DUPLICATE KEY UPDATE v=VALUES(v);
INSERT INTO setting (k,v) VALUES ('ffprobe_path','/usr/bin/ffprobe') ON DUPLICATE KEY UPDATE v=VALUES(v);
" 2>&1 && echo ok

echo
echo "=== 6. 执行迁移 ==="
php bin/migrate.php 2>&1 | tail -12

echo
echo "=== 7. 迁移状态 ==="
php bin/migrate.php --list 2>&1 | tail -10

echo
echo "=== 8. 表结构确认 ==="
mysql anime_new -e "SHOW COLUMNS FROM episode_subtitle" 2>&1
mysql anime_new -e "SHOW COLUMNS FROM episode LIKE 'sub_extract%'" 2>&1
