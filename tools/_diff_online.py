#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
对比「线上拉取的版本」与「本地工作副本」的差异。

线上目录布局与本地不同（部署脚本做了映射）：
    本地 frontend/src/...      <->  线上 frontend-src/src/...
其余（backend/ tools/ deploy/ *.md）路径一致。

输出：内容不同的文件、只在一边存在的文件。
"""
import hashlib
import os
import sys

LOCAL = r"C:\Users\zeng\WorkBuddy\2026-09-12-00-12-45\anime-new"
ONLINE = r"D:\A test gramer\NewAnimeSYS-online"

# (本地根, 线上根, 标签)
PAIRS = [
    (os.path.join(LOCAL, "backend"), os.path.join(ONLINE, "backend"), "backend"),
    (os.path.join(LOCAL, "frontend", "src"), os.path.join(ONLINE, "frontend-src", "src"), "frontend"),
    (os.path.join(LOCAL, "tools"), os.path.join(ONLINE, "tools"), "tools"),
    (os.path.join(LOCAL, "deploy"), os.path.join(ONLINE, "deploy"), "deploy"),
]

SKIP = {"node_modules", ".venv", "__pycache__", ".git", "dist", "storage"}


def walk(root):
    out = {}
    if not os.path.isdir(root):
        return out
    for dp, dn, fn in os.walk(root):
        dn[:] = [d for d in dn if d not in SKIP]
        for f in fn:
            p = os.path.join(dp, f)
            rel = os.path.relpath(p, root).replace(os.sep, "/")
            try:
                h = hashlib.md5(open(p, "rb").read()).hexdigest()
                out[rel] = (h, os.path.getsize(p))
            except Exception:
                pass
    return out


def main():
    print("本地副本 : %s" % LOCAL)
    print("线上拉取 : %s" % ONLINE)
    print()

    total_diff = 0
    total_only_local = 0
    total_only_online = 0

    for lr, orr, tag in PAIRS:
        L = walk(lr)
        O = walk(orr)
        diff = [k for k in sorted(set(L) & set(O)) if L[k][0] != O[k][0]]
        only_l = sorted(set(L) - set(O))
        only_o = sorted(set(O) - set(L))
        same = len(set(L) & set(O)) - len(diff)

        print("── %s ──  相同 %d / 本地独有 %d / 线上独有 %d / 内容不同 %d"
              % (tag, same, len(only_l), len(only_o), len(diff)))

        for k in diff:
            print("   不同  %-58s 本地 %-8d 线上 %-8d"
                  % (k, L[k][1], O[k][1]))
        for k in only_l[:12]:
            print("   仅本地 %-58s %d B" % (k, L[k][1]))
        if len(only_l) > 12:
            print("   ... 仅本地还有 %d 个" % (len(only_l) - 12))
        for k in only_o[:12]:
            print("   仅线上 %-58s %d B" % (k, O[k][1]))
        if len(only_o) > 12:
            print("   ... 仅线上还有 %d 个" % (len(only_o) - 12))
        print()

        total_diff += len(diff)
        total_only_local += len(only_l)
        total_only_online += len(only_o)

    # 顶层文档
    for f in ("ARCHITECTURE.md", "README.md"):
        a = os.path.join(LOCAL, f)
        b = os.path.join(ONLINE, f)
        if os.path.isfile(a) and os.path.isfile(b):
            ha = hashlib.md5(open(a, "rb").read()).hexdigest()
            hb = hashlib.md5(open(b, "rb").read()).hexdigest()
            print("── %s ── %s" % (f, "相同" if ha == hb else "不同"))

    print()
    print("小结: 内容不同 %d 个 | 仅本地 %d 个 | 仅线上 %d 个"
          % (total_diff, total_only_local, total_only_online))


if __name__ == "__main__":
    main()
