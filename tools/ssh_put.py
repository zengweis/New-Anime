#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
递归上传本地目录到服务器。
    python tools/ssh_put.py <local_dir> <remote_dir>
    python tools/ssh_put.py anime-new/backend /opt/anime-new/backend
"""
import os
import sys
import posixpath

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from sshconn import connect  # noqa: E402

HOST = os.environ.get("AN_HOST", "<server-ip>")

SKIP_DIRS = {".git", "node_modules", ".venv", "__pycache__", ".idea", "dist"}


def mkdirs(sftp, remote: str) -> None:
    parts = []
    p = remote
    while p and p != "/":
        parts.append(p)
        p = posixpath.dirname(p)
    for d in reversed(parts):
        try:
            sftp.stat(d)
        except IOError:
            try:
                sftp.mkdir(d)
            except IOError:
                pass


def put_dir(sftp, local: str, remote: str, counter: list) -> None:
    mkdirs(sftp, remote)
    for entry in sorted(os.listdir(local)):
        if entry in SKIP_DIRS:
            continue
        lp = os.path.join(local, entry)
        rp = posixpath.join(remote, entry)
        if os.path.isdir(lp):
            put_dir(sftp, lp, rp, counter)
        else:
            sftp.put(lp, rp)
            counter[0] += 1
            print("  -> %s" % rp)


def main() -> int:
    if len(sys.argv) < 3:
        print(__doc__)
        return 1

    local = os.path.abspath(sys.argv[1])
    remote = sys.argv[2]
    if not os.path.exists(local):
        print("本地路径不存在: %s" % local)
        return 1

    c = connect()
    sftp = c.open_sftp()
    counter = [0]
    try:
        if os.path.isfile(local):
            sftp.put(local, remote)
            counter[0] = 1
            print("  -> %s" % remote)
        else:
            put_dir(sftp, local, remote, counter)
    finally:
        sftp.close()
        c.close()

    print("共上传 %d 个文件 -> %s" % (counter[0], remote))
    return 0


if __name__ == "__main__":
    sys.exit(main())
