"""同步本地 nginx 配置：所有含字幕 vtt location 的文件都补上图片字幕路由。

注意：含 {n,m} 的正则必须整体加引号，否则 nginx 的配置解析器把 { 当成块起始，
报 'pcre2_compile() failed: missing closing parenthesis' —— 看起来像正则写错了，
其实是引号问题。
"""
import glob
import os

BLOCK = """
# 图片字幕：时间清单 + 逐屏图片（由 <img src> 发起，令牌同样只能走 query）
# 正则里的 {3,6} 必须靠整体加引号才能过 nginx 的配置解析器（它按花括号切块）
location ~ "^/subtitle/(\\d+)/(\\d+)/(manifest\\.json|[0-9]{3,6}\\.(?:webp|png|jpg))$" {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.5-fpm.sock;
    fastcgi_param SCRIPT_FILENAME /opt/anime-new/backend/public/subtitle.php;
    fastcgi_param SCRIPT_NAME     /subtitle.php;
    fastcgi_param QUERY_STRING    id=$1&track=$2&file=$3&$args;
    fastcgi_read_timeout 60s;
    fastcgi_buffering on;
}
"""

for p in sorted(glob.glob('deploy/nginx*/*.conf') + glob.glob('deploy/nginx*.conf')):
    if not os.path.isfile(p):
        continue
    s = open(p, encoding='utf-8').read()
    if '.vtt' not in s or 'subtitle' not in s:
        continue
    if 'manifest' in s:
        print('already:', p)
        continue
    lines = s.split(chr(10))
    start = next((i for i, l in enumerate(lines) if 'location ~ ' in l and '.vtt' in l), None)
    if start is None:
        print('!! 没找到 vtt location:', p)
        continue
    depth = 0
    end = None
    for i in range(start, len(lines)):
        depth += lines[i].count('{') - lines[i].count('}')
        if i > start and depth <= 0:
            end = i
            break
    out = chr(10).join(lines[: end + 1]) + chr(10) + BLOCK + chr(10).join(lines[end + 1 :])
    open(p, 'w', encoding='utf-8').write(out)
    print('patched:', p)
