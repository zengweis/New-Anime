"""把 anime-new 的指定子目录上传到服务器（复用 sshconn 的 SFTP）。

用法：
    python tools/deploy_push.py backend/src backend/public backend/bin
    python tools/deploy_push.py frontend/src=>src          # 本地 frontend/src 传到远端 <root>/src
    python tools/deploy_push.py --remote-root /opt/anime-new/frontend-src frontend/src=>src

为什么要 `=>` 映射：
    本地目录结构（anime-new/frontend/…）和服务器上的构建目录（/opt/anime-new/frontend-src/…）
    名字不一样，直接拼相对路径会传到一个没人读的新目录里去——表现为「传了改动了但构建
    出来的还是旧代码」。用 `本地路径=>远端相对路径` 把映射写清楚，避免踩这个坑。
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from sshconn import connect  # noqa: E402

SKIP_DIRS = {"node_modules", "dist", ".git", "__pycache__", "storage", "config"}
SKIP_FILES = {"config.php"}


def main() -> int:
    args = sys.argv[1:]
    remote_root = "/opt/anime-new"
    if "--remote-root" in args:
        i = args.index("--remote-root")
        remote_root = args[i + 1]
        del args[i : i + 2]
    if not args:
        print("用法：deploy_push.py [--remote-root /opt/anime-new] <本地相对路径>[=>远端相对路径] ...")
        return 1

    pairs = []
    for a in args:
        if "=>" in a:
            loc, rem = a.split("=>", 1)
        else:
            loc, rem = a, a
        pairs.append((loc.strip(), rem.strip().strip("/")))

    base = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
    c = connect()
    sftp = c.open_sftp()

    def ensure(path: str) -> None:
        cur = ""
        for part in path.strip("/").split("/"):
            cur += "/" + part
            try:
                sftp.stat(cur)
            except IOError:
                try:
                    sftp.mkdir(cur)
                except IOError:
                    pass

    total = 0
    for rel, rel_remote in pairs:
        local = os.path.join(base, rel)
        remote = remote_root + "/" + rel_remote
        if os.path.isfile(local):
            ensure(os.path.dirname(remote))
            sftp.put(local, remote)
            total += 1
            print(f"  {rel} -> {remote}")
            continue

        if not os.path.isdir(local):
            print(f"  !! 本地不存在：{rel}")
            continue

        for root, dirs, files in os.walk(local):
            dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
            rel_dir = os.path.relpath(root, local)
            rdir = remote if rel_dir == "." else remote + "/" + rel_dir.replace(os.sep, "/")
            ensure(rdir)
            for f in files:
                if f in SKIP_FILES:
                    continue
                sftp.put(os.path.join(root, f), rdir + "/" + f)
                total += 1
        print(f"  {rel} -> {remote}")

    sftp.close()
    c.close()
    print(f"共上传 {total} 个文件")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
