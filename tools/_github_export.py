"""把 anime-new 脱敏导出到 anime-new-export，初始化 git 并推到 GitHub。

安全要点：
  · 本地工作副本一个字节都不动 —— 所有脱敏都发生在导出副本上
  · config.php 整个剔除（仓库里只保留 config.example.php）
  · 密码/IP 字面量全部替换成占位符，推之前再全量扫一遍确认清零
  · token 只出现在 push 那一次命令里，不进 remote 配置、不进任何落盘文件，
    所有打印都过 sanitize
"""
import os
import re
import shutil
import subprocess
import sys

ROOT = r'C:\Users\zeng\WorkBuddy\2026-09-12-00-12-45'
SRC = os.path.join(ROOT, 'anime-new')
DST = os.path.join(ROOT, 'anime-new-export')
TOK = os.environ['GH_TOKEN']
REMOTE = 'https://github.com/zengweis/New-Anime.git'

# 需要替换掉的字面量 -> 占位符
SCRUB = [
    ('<server-password>', '<server-password>'),
    ('<server-password>', '<server-password>'),
    ('<db-password>', '<db-password>'),
    ('<password>', '<password>'),
    ('<server-ip>', '<server-ip>'),
    ('<server-ip>', '<server-ip>'),
]

SKIP_DIRS = {'node_modules', '.venv', '__pycache__', '.git', 'dist', '.vite', 'storage'}
SKIP_EXT = {'.pyc', '.log', '.tmp', '.zip'}
TEXT_EXT = {'.php', '.py', '.js', '.vue', '.ts', '.sql', '.md', '.sh', '.txt', '.json',
            '.conf', '.css', '.html', '.ini', '.yml', '.yaml', '.xml', '.env', '.example'}


def sanitize(s: str) -> str:
    return s.replace(TOK, '***')


def copy_scrub():
    if os.path.exists(DST):
        shutil.rmtree(DST)
    os.makedirs(DST)
    n = 0
    scrubbed_files = []
    for dp, dn, fn in os.walk(SRC):
        rel = os.path.relpath(dp, SRC)
        dn[:] = [d for d in dn if d not in SKIP_DIRS]
        for f in fn:
            ext = os.path.splitext(f)[1].lower()
            if ext in SKIP_EXT:
                continue
            sp = os.path.join(dp, f)
            rp = os.path.normpath(os.path.join(rel, f)) if rel != '.' else f
            if rp.replace('\\', '/') == 'backend/config/config.php':
                continue  # 真配置整个不进仓库
            dp_out = os.path.join(DST, rp)
            os.makedirs(os.path.dirname(dp_out) or DST, exist_ok=True)

            if ext in TEXT_EXT or f in ('.gitignore',) or f.endswith('.example.php'):
                try:
                    text = open(sp, encoding='utf-8', errors='ignore').read()
                except OSError:
                    continue
                new = text
                for a, b in SCRUB:
                    new = new.replace(a, b)
                if new != text:
                    scrubbed_files.append(rp.replace('\\', '/'))
                open(dp_out, 'w', encoding='utf-8', newline='\n').write(new)
            else:
                shutil.copy2(sp, dp_out)
            n += 1
    return n, scrubbed_files


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


def git(*args, env=None, cwd=None):
    e = dict(os.environ)
    e.setdefault('http_proxy', 'http://127.0.0.1:7897')
    e.setdefault('https_proxy', 'http://127.0.0.1:7897')
    if env:
        e.update(env)
    r = subprocess.run(['git', *args], cwd=cwd or DST, env=e,
                       capture_output=True, text=True, encoding='utf-8', errors='replace')
    return r.returncode, sanitize(r.stdout + r.stderr)


def main():
    n, scrubbed = copy_scrub()
    print('导出文件数:', n)
    print('被脱敏的文件:', len(scrubbed))
    for f in scrubbed:
        print('  ·', f)

    # ---- 推之前必须清零：全部针（含占位符不该再出现的原值）再扫一遍 ----
    leftovers = []
    for dp, dn, fn in os.walk(DST):
        dn[:] = [d for d in dn if d not in ('.git',)]
        for f in fn:
            p = os.path.join(dp, f)
            try:
                s = open(p, encoding='utf-8', errors='ignore').read()
            except OSError:
                continue
            for a, _ in SCRUB:
                if a in s:
                    leftovers.append((p, a))
    if leftovers:
        print('\n!! 还有残留密钥，中止：')
        for p, a in leftovers:
            print('  ', p, '->', a)
        sys.exit(1)
    print('\n密钥复查：全部清零 ✓')

    open(os.path.join(DST, '.gitignore'), 'w', encoding='utf-8').write(GITIGNORE)

    msg_path = os.path.join(DST, '.git_msg.txt')
    open(msg_path, 'w', encoding='utf-8').write(
        '自建看番系统 anime-new\n\n'
        'dmhy RSS → 规则匹配 → qBittorrent 下载 → 同步入库 → 浏览器播放。\n'
        '栈：PHP 8.5（无框架）+ MySQL 8 + Vue 3/Vite + nginx + qBittorrent。\n'
        '含：remux 转 MP4、服务端字幕抽取、PGS 位图字幕（OCR/图片叠加）、'
        '对象存储（R2 手写 SigV4）、许愿墙、弹幕。\n\n'
        '本仓库为脱敏发布版：config.php 不进仓库（用 config.example.php），'
        '部署脚本里的密码与 IP 均为占位符。\n'
    )

    env = {
        'GIT_AUTHOR_NAME': 'JERRY WEI',
        'GIT_AUTHOR_EMAIL': 'zeng@tsunako.fun',
        'GIT_COMMITTER_NAME': 'JERRY WEI',
        'GIT_COMMITTER_EMAIL': 'zeng@tsunako.fun',
    }
    steps = [
        (['init', '-b', 'main'], env),
        (['add', '-A'], env),
        (['commit', '-F', msg_path], env),
    ]
    for args, e in steps:
        rc, out = git(*args, env=e)
        print('$ git', ' '.join(args), '\n', out.strip()[:400])
        if rc != 0:
            print('!! 失败，中止'); sys.exit(1)
    os.remove(msg_path)

    # remote 只存干净 URL；push 用带 token 的内联 URL（不落盘）
    rc, out = git('remote', 'add', 'origin', REMOTE)
    print('$ git remote add origin', REMOTE, '→', rc)

    rc, out = git('push', 'https://zengweis:%s@github.com/zengweis/New-Anime.git' % TOK,
                  'main:main', env=env)
    print('$ git push origin main\n', out.strip()[-800:])
    if rc != 0:
        print('!! push 失败'); sys.exit(1)

    print('\n完成：https://github.com/zengweis/New-Anime')


if __name__ == '__main__':
    main()
