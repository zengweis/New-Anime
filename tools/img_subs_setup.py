"""位图字幕图片叠加：语法检查 + 迁移 + 前台渲染 ep80 + 登记。"""
import os
import sys
import time

os.environ.setdefault('AN_HOST', '<server-ip>')
os.environ.setdefault('AN_PASS', '<server-password>')
os.environ.setdefault('AN_RETRIES', '1')
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', '..', '..', 'anime-new', 'tools'))

from sshconn import connect  # noqa: E402

c = connect()


def sh(cmd, t=900):
    _, o, e = c.exec_command(cmd, timeout=t)
    return (o.read().decode('utf-8', 'replace') + e.read().decode('utf-8', 'replace')).strip()


print('=== PHP 语法 ===')
files = [
    'src/Service/SubtitleService.php',
    'src/Service/SubtitleImage.php',
    'public/subtitle.php',
    'src/Controller/AnimeController.php',
    'src/Controller/AdminSettingController.php',
    'bin/daemon.php',
    'bin/ocr_subs.php',
]
for f in files:
    print(' ', f, '->', sh('cd /opt/anime-new/backend && php -l ' + f + ' 2>&1 | tail -1'))
print(' python:', sh('/opt/anime-new/venv-ocr/bin/python -m py_compile /opt/anime-new/backend/bin/pgs_to_images.py && echo OK'))

print()
print('=== 迁移 ===')
print(sh('cd /opt/anime-new/backend && php bin/migrate.php 2>&1 | tail -12'))

print()
print('=== 设置项 ===')
print(sh("cd /opt/anime-new/backend && php -r \"require 'src/bootstrap.php'; "
         "foreach (['subs_bitmap_mode','subs_img_format','subs_img_quality','subs_img_threshold','subs_sup_keep'] as $k) "
         "printf('%s = %s' . PHP_EOL, $k, var_export(\\App\\Core\\Setting::get($k,'<none>'), true));\" 2>&1"))

print()
print('=== 环境自检 ===')
print(sh('cd /opt/anime-new/backend && php bin/ocr_subs.php env 2>&1'))

print()
print('=== 前台渲染 ep80 的字幕图片 ===')
t0 = time.time()
print(sh('cd /opt/anime-new/backend && php bin/ocr_subs.php render 80 2>&1', 1800))
print('  耗时 %.1fs' % (time.time() - t0))

print()
print('=== 产物 ===')
print(sh('du -sh /data/anime-subs/80/img* 2>/dev/null; ls /data/anime-subs/80/img*/ | head -3; '
         'python3 -c "import json;d=json.load(open([__import__(\'glob\').glob(\'/data/anime-subs/80/img*/manifest.json\')[0]] and __import__(\'glob\').glob(\'/data/anime-subs/80/img*/manifest.json\')[0]));'
         'print(\'size\', d[\'width\'], \'x\', d[\'height\']); print(\'count\', d[\'count\']); print(\'stats\', d[\'stats\']); print(d[\'cues\'][0]); print(d[\'cues\'][80])"'))

print()
print('=== 字幕轨（应含 image 与 ocr 两条）===')
print(sh("cd /opt/anime-new/backend && php -r \"require 'src/bootstrap.php'; "
         "foreach (\\App\\Service\\SubtitleService::tracks(80) as $t) "
         "printf('%d %-8s %-10s %s (cues=%d)' . PHP_EOL, $t['track_index'], $t['kind'], $t['codec'], $t['label'], $t['cues']);\" 2>&1"))

c.close()
