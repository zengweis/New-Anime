#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
把线上（服务器 /opt/anime-new）的代码拉到本地目录。

用法：
    set AN_HOST=<server-ip>
    set AN_PASS=<服务器 root 密码>
    python tools/_pull_online.py <本地目标目录>

排除项（这些是运行时产物 / 依赖，不是源码，拉下来没意义且很大）：
    venv-ocr/          OCR 虚拟环境（312M）
    node_modules/      前端依赖（86M）
    dist/              构建产物目录（另有需要可加 --with-dist）
    *.log / storage/logs/   运行时日志
    __pycache__/

只下载、不删除本地已有文件 —— 所以可以安全地对已有目录做增量覆盖。
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

REMOTE_ROOT = "/opt/anime-new"

EXCLUDE_DIRS = ("venv-ocr", "node_modules", "dist", "__pycache__", ".git")
EXCLUDE_PATH_PARTS = ("/storage/logs/", "/node_modules/", "/venv-ocr/", "/dist/", "/__pycache__/")


def build_file_list(sh):
    """在服务器上 find 出要下载的相对路径列表。"""
    cmd = (
        "cd %s && find . -type f "
        "-not -path '*/node_modules/*' "
        "-not -path '*/venv-ocr/*' "
        "-not -path '*/dist/*' "
        "-not -path '*/__pycache__/*' "
        "-not -path '*/storage/logs/*' "
        "-not -name '*.log' "
        "| sed 's|^\\./||' | sort" % REMOTE_ROOT
    )
    out = sh(cmd, 240)
    return [x.strip() for x in out.splitlines() if x.strip()]


def main():
    if len(sys.argv) < 2:
        print("用法: python tools/_pull_online.py <本地目标目录>")
        sys.exit(1)

    dest = os.path.abspath(sys.argv[1])
    with_dist = "--with-dist" in sys.argv
    if with_dist:
        EXCLUDE_PATH_PARTS_LOCAL = tuple(p for p in EXCLUDE_PATH_PARTS if p != "/dist/")
    else:
        EXCLUDE_PATH_PARTS_LOCAL = EXCLUDE_PATH_PARTS

    os.environ["AN_RETRIES"] = "1"
    from sshconn import connect

    c = connect()
    sftp = c.open_sftp()

    def sh(cmd, t=240):
        _, o, e = c.exec_command(cmd, timeout=t)
        return (o.read().decode("utf-8", "replace") + e.read().decode("utf-8", "replace")).strip()

    files = build_file_list(sh)
    files = [f for f in files if not any(p in ("/" + f) for p in EXCLUDE_PATH_PARTS_LOCAL)]

    print("待下载: %d 个文件 -> %s" % (len(files), dest))

    ok = 0
    skip = 0
    fail = []

    for i, rel in enumerate(files, 1):
        remote = REMOTE_ROOT + "/" + rel
        local = os.path.join(dest, rel.replace("/", os.sep))

        # 目录名层面再兜一层（find 已过滤，这里防路径变体）
        parts = rel.split("/")
        if any(d in EXCLUDE_DIRS for d in parts[:-1]) and not with_dist:
            skip += 1
            continue
        if not with_dist and "dist" in parts[:-1]:
            skip += 1
            continue

        os.makedirs(os.path.dirname(local), exist_ok=True)
        try:
            sftp.get(remote, local)
            ok += 1
        except Exception as e:
            fail.append((rel, type(e).__name__, str(e)[:80]))

        if i % 200 == 0:
            print("  ...%d/%d" % (i, len(files)))

    print()
    print("完成: 成功 %d, 跳过 %d, 失败 %d" % (ok, skip, len(fail)))
    for f in fail[:15]:
        print("  失败:", f)

    sftp.close()
    c.close()


if __name__ == "__main__":
    main()
