"""把 anime-new 脱敏导出到 anime-new-export，提交、打 v1.0.1 tag 并推到 GitHub。

相对初版的变化（v1.0.1）：
  · 保留 .git —— 远端有 3 个 GitHub 网页端 README 提交，不能再 init 出一条孤立历史
  · 同步删除：源里没了的文件在导出副本里也删掉，git add -A 才会记 deletion
  · SCRUB 补进新凭据；域名 acgn.help 保留（作者自己在 README 里公开了）
  · README.md 以远端版本为底（含作者自述与博客链接），只改版本号，避免覆盖网页端改动
  · tag v1.0.1 + 通过 API 建 Release

安全要点：
  · 本地工作副本一个字节都不动 —— 所有脱敏都发生在导出副本上
  · config.php 整个剔除（仓库里只保留 config.example.php）
  · 推之前全量扫一遍确认敏感字面量清零
  · token 只出现在 push 那一次命令里，不进 remote 配置、不落盘；所有打印过 sanitize
"""
import json
import os
import shutil
import subprocess
import sys

ROOT = r'C:\Users\zeng\WorkBuddy\2026-09-12-00-12-45'
SRC = os.path.join(ROOT, 'anime-new')
DST = os.path.join(ROOT, 'anime-new-export')
REMOTE = 'https://github.com/zengweis/New-Anime.git'
REPO_API = 'https://api.github.com/repos/zengweis/New-Anime'
TAG = 'v1.0.1'
PROXY = 'http://127.0.0.1:7897'


def load_token():
    if os.environ.get('GH_TOKEN'):
        return os.environ['GH_TOKEN']
    p = os.path.expanduser('~/.workbuddy/mcp.json')
    d = json.loads(open(p, encoding='utf-8').read())
    return (d.get('mcpServers', {})
            .get('github-pat', {})
            .get('env', {})
            .get('GITHUB_PERSONAL_ACCESS_TOKEN'))


TOK = load_token()
if not TOK:
    print('!! 没拿到 GitHub token（设 GH_TOKEN 或填 ~/.workbuddy/mcp.json）')
    sys.exit(1)

# 需要替换掉的字面量 -> 占位符。长串放前面，避免被短串先替换掉
SCRUB = [
    ('<server-password>', '<server-password>'),
    ('<server-password>', '<server-password>'),
    ('<server-password>', '<server-password>'),
    ('<db-password>', '<db-password>'),
    ('<db-password>', '<db-password>'),
    ('<server-ip>', '<server-ip>'),
    ('<server-ip>', '<server-ip>'),
    ('<password>', '<password>'),
]

SKIP_DIRS = {'node_modules', '.venv', '__pycache__', '.git', 'dist', '.vite', 'storage'}
SKIP_EXT = {'.pyc', '.log', '.tmp', '.zip'}
# 本地一次性排查脚本，不进仓库
SKIP_FILES = {'_scan_secrets.py', '_gh_check.py', '_remote_README.md'}
TEXT_EXT = {'.php', '.py', '.js', '.vue', '.ts', '.sql', '.md', '.sh', '.txt', '.json',
            '.conf', '.css', '.html', '.ini', '.yml', '.yaml', '.xml', '.env', '.example'}

GITIGNORE = """# 运行时与密钥（绝不进仓库）
backend/config/config.php
backend/storage/
*.log

# 依赖与构建产物
node_modules/
dist/
.venv/
__pycache__/
*.pyc

# 系统垃圾
.DS_Store
Thumbs.db
"""


def sanitize(s):
    return s.replace(TOK, '***')


def collect():
    """扫描源目录，返回 {相对路径: 绝对路径}。"""
    out = {}
    for dp, dn, fn in os.walk(SRC):
        dn[:] = [d for d in dn if d not in SKIP_DIRS]
        for f in fn:
            ext = os.path.splitext(f)[1].lower()
            if ext in SKIP_EXT or f in SKIP_FILES:
                continue
            rp = os.path.relpath(os.path.join(dp, f), SRC).replace('\\', '/')
            if rp == 'backend/config/config.php':
                continue  # 真配置整个不进仓库
            out[rp] = os.path.join(dp, f)
    return out


def scrub_text(text):
    new = text
    for a, b in SCRUB:
        new = new.replace(a, b)
    return new


def sync(targets):
    """把源同步到导出副本：多出来的删掉，其余覆盖写。返回 (写入数, 删除列表, 脱敏列表)。"""
    removed = []
    for dp, dn, fn in os.walk(DST):
        dn[:] = [d for d in dn if d != '.git']
        for f in fn:
            sp = os.path.join(dp, f)
            rp = os.path.relpath(sp, DST).replace('\\', '/')
            if rp not in targets:
                os.remove(sp)
                removed.append(rp)

    written = 0
    scrubbed = []
    for rp, sp in sorted(targets.items()):
        if rp == 'README.md':
            continue  # README 单独处理，保住网页端改动
        dp_out = os.path.join(DST, rp)
        os.makedirs(os.path.dirname(dp_out) or DST, exist_ok=True)
        ext = os.path.splitext(rp)[1].lower()
        base = os.path.basename(rp)
        if ext in TEXT_EXT or base in ('.gitignore',) or base.endswith('.example.php'):
            try:
                text = open(sp, encoding='utf-8', errors='ignore').read()
            except OSError:
                continue
            new = scrub_text(text)
            if new != text:
                scrubbed.append(rp)
            open(dp_out, 'w', encoding='utf-8', newline='\n').write(new)
        else:
            shutil.copy2(sp, dp_out)
        written += 1
    return written, removed, scrubbed


def git(*args, env=None, timeout=120):
    """所有 git 网络操作都强制 HTTP/1.1 —— 经 Clash 时 HTTP/2 会挂起（fetch/push 都会）。"""
    e = dict(os.environ)
    e['http_proxy'] = PROXY
    e['https_proxy'] = PROXY
    e['GIT_TERMINAL_PROMPT'] = '0'  # 别弹凭据框，卡住就没输出了
    if env:
        e.update(env)
    cmd = ['git', '-c', 'http.version=HTTP/1.1',
           '-c', 'http.postBuffer=524288000', *args]
    try:
        r = subprocess.run(cmd, cwd=DST, env=e, capture_output=True,
                           text=True, encoding='utf-8', errors='replace',
                           timeout=timeout)
    except subprocess.TimeoutExpired:
        print('!! git', ' '.join(a for a in args if not a.startswith('https')),
              '超时 %ss' % timeout)
        return 124, 'timeout'
    return r.returncode, sanitize((r.stdout + r.stderr).strip())


def git_http1(*args, env=None):
    return git(*args, env=env)


IDENT = {
    'GIT_AUTHOR_NAME': 'JERRY WEI',
    'GIT_AUTHOR_EMAIL': 'zeng@tsunako.fun',
    'GIT_COMMITTER_NAME': 'JERRY WEI',
    'GIT_COMMITTER_EMAIL': 'zeng@tsunako.fun',
}


def main():
    if not os.path.isdir(os.path.join(DST, '.git')):
        print('!! 导出副本没有 .git，先跑一次初版导出脚本初始化')
        sys.exit(1)

    token_url = 'https://zengweis:%s@github.com/zengweis/New-Anime.git' % TOK

    # ---- 0) 拉远端最新（网页端改过 README），以远端为底 ----
    rc, out = git('fetch', token_url, 'main')
    print('$ git fetch origin main ->', rc)
    if rc != 0:
        print(out[-600:])
        sys.exit(1)
    rc, head = git('rev-parse', 'FETCH_HEAD')
    print('  FETCH_HEAD =', head)

    rc, out = git('checkout', '-B', 'main', 'FETCH_HEAD')
    print('$ git checkout -B main FETCH_HEAD ->', rc)
    if rc != 0:
        print(out[-600:])
        sys.exit(1)

    # ---- 1) 同步文件 ----
    targets = collect()
    written, removed, scrubbed = sync(targets)
    print('同步文件数:', written)
    print('删除（源里已无）:', len(removed))
    for f in removed[:20]:
        print('  -', f)
    print('被脱敏的文件:', len(scrubbed))
    for f in scrubbed:
        print('  ·', f)

    # ---- 2) README：以远端版本为底，只改版本号 ----
    remote_md = open(os.path.join(SRC, 'tools', '_remote_README.md'),
                     encoding='utf-8').read()
    if '自建看番系统 \n' in remote_md:
        merged = remote_md.replace('自建看番系统 \n',
                                   '自建看番系统 · **%s** · 2026-10-11\n' % TAG)
    else:
        merged = remote_md.replace('# NewAnimeSYS\n',
                                   '# NewAnimeSYS\n\n**版本 %s** · 2026-10-11\n' % TAG, 1)
    open(os.path.join(DST, 'README.md'), 'w', encoding='utf-8', newline='\n').write(merged)
    print('README.md：沿用远端版本，版本号改为', TAG)

    open(os.path.join(DST, '.gitignore'), 'w', encoding='utf-8').write(GITIGNORE)

    # ---- 3) 推之前必须清零：敏感字面量复查 ----
    leftovers = []
    for dp, dn, fn in os.walk(DST):
        dn[:] = [d for d in dn if d != '.git']
        for f in fn:
            p = os.path.join(dp, f)
            rp = os.path.relpath(p, DST).replace('\\', '/')
            if rp == 'tools/_github_export.py':
                continue  # 脚本自身的 SCRUB 表会被自己的规则替换掉，属预期
            try:
                s = open(p, encoding='utf-8', errors='ignore').read()
            except OSError:
                continue
            for a, _ in SCRUB:
                if a in s:
                    leftovers.append((rp, a))
    if leftovers:
        print('\n!! 还有残留敏感字面量，中止：')
        for p, a in leftovers:
            print('  ', p, '->', a)
        sys.exit(1)
    print('敏感字面量复查：全部清零')

    # ---- 4) 提交 ----
    rc, out = git('add', '-A')
    print('$ git add -A ->', rc)
    rc, out = git('status', '--short')
    changes = [l for l in out.splitlines() if l.strip()]
    print('$ git status --short  共 %d 项' % len(changes))
    for l in changes[:40]:
        print('  ', l)
    if len(changes) > 40:
        print('   ...还有', len(changes) - 40, '项')

    if not changes:
        print('\n没有需要提交的改动')
    else:
        msg_path = os.path.join(DST, '.git_msg.txt')
        open(msg_path, 'w', encoding='utf-8').write(
            'release %s\n\n'
            '自建看番系统：dmhy RSS → 规则匹配 → qBittorrent 下载 → 同步入库 → 浏览器播放。\n'
            '栈：PHP 8.5（无框架）+ MySQL 8 + Vue 3/Vite + nginx + qBittorrent。\n'
            '含：服务端 remux 转 MP4、服务端字幕抽取、PGS 位图字幕（OCR / 图片叠加）、\n'
            '对象存储（R2 手写 SigV4）、存储占用统计、许愿墙、弹幕。\n\n'
            '本仓库为脱敏发布版：config.php 不进仓库（用 config.example.php），\n'
            '部署脚本里的密码与 IP 均为占位符。\n' % TAG
        )
        rc, out = git('commit', '-F', msg_path, env=IDENT)
        print('$ git commit ->', rc)
        print(' ', out[:400])
        if rc != 0:
            sys.exit(1)
        os.remove(msg_path)

    # ---- 5) 打 tag ----
    rc, out = git('tag', '-f', '-a', TAG, '-m', 'release %s' % TAG, env=IDENT)
    print('$ git tag -a %s ->' % TAG, rc)

    # ---- 6) push（remote 只存干净 URL；token 走内联，不落盘）----
    rc, out = git('remote', 'set-url', 'origin', REMOTE)
    print('$ git remote set-url origin', REMOTE, '->', rc)

    rc, out = git_http1('push', token_url, 'main:main')
    print('$ git push origin main ->', rc)
    print(' ', out[-700:])
    if rc != 0:
        sys.exit(1)

    rc, out = git_http1('push', '-f', token_url, 'refs/tags/' + TAG)
    print('$ git push origin %s ->' % TAG, rc)
    print(' ', out[-500:])
    if rc != 0:
        sys.exit(1)

    print('\n完成：https://github.com/zengweis/New-Anime/tree/%s' % TAG)

    # ---- 7) 建 Release ----
    import httpx
    body = '\n'.join([
        '自建看番系统 **%s**' % TAG,
        '',
        'dmhy RSS → 规则匹配 → qBittorrent 自动下载 → 同步入库 → 浏览器播放。',
        '',
        '## 本次相对 v1 的改动',
        '',
        '- PGS/DVD 位图字幕：`image` 模式（按原时机/位置/调色板渲透明 WebP 叠加）与 `ocr` 模式',
        '- 对象存储 R2：手写 SigV4 客户端，上传校验通过后才删本地文件',
        '- 存储占用统计拆成本机真扫盘 / 远端 `SUM(s3_size)` 两个互不重叠的口径',
        '- 播放格式判定只看实际会发出的文件（`s3_key`），修掉远端 MP4 被误判成 MKV 的问题',
        '- 服务端 remux 优先（ffmpeg `-c copy` 零转码转 MP4），前端 demux 仅回退',
        '- 服务端字幕抽取 + `subtitle.php` 分发（query token + ETag 304）',
        '',
        '## 说明',
        '',
        '脱敏发布版：`backend/config/config.php` 不进仓库，请用 `config.example.php` 自建；',
        '部署脚本里的服务器密码与 IP 均为占位符，使用前自行替换。',
    ])
    cli = httpx.Client(proxy=PROXY, timeout=30,
                       headers={'Authorization': 'Bearer ' + TOK,
                                'Accept': 'application/vnd.github+json'})
    r = cli.post(REPO_API + '/releases',
                 json={'tag_name': TAG, 'name': TAG, 'body': body,
                       'draft': False, 'prerelease': False})
    print('POST /releases ->', r.status_code)
    if r.status_code in (200, 201):
        print('  ', r.json().get('html_url'))
    else:
        print(' ', r.text[:300])


if __name__ == '__main__':
    main()
