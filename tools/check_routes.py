#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""核对前端调用的接口与后端注册的路由是否一一对应。"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FE = os.path.join(ROOT, "frontend", "src")
BE_INDEX = os.path.join(ROOT, "backend", "public", "index.php")

METHOD_MAP = {"get": "GET", "post": "POST", "put": "PUT", "del": "DELETE", "delete": "DELETE"}

# ---------------- 前端 ----------------
fe_calls = set()
call_re = re.compile(r"api\.(get|post|put|del|delete)\(\s*[`'\"]([^`'\"]+)")

for dirpath, _dirs, files in os.walk(FE):
    for fn in files:
        if not fn.endswith((".vue", ".js")):
            continue
        p = os.path.join(dirpath, fn)
        with open(p, encoding="utf-8") as fh:
            src = fh.read()
        for m in call_re.finditer(src):
            method = METHOD_MAP[m.group(1)]
            path = m.group(2)
            # 模板变量 -> 统一占位
            path = re.sub(r"\$\{[^}]+\}", "{id}", path)
            # 去掉尾随 ? / 空白
            path = path.split("?")[0].rstrip("/")
            if path.startswith("/api"):
                fe_calls.add((method, path))

# ---------------- 后端 ----------------
with open(BE_INDEX, encoding="utf-8") as fh:
    lines = fh.readlines()

be_routes = set()
prefix_stack = []
group_re = re.compile(r"\$r->group\(\s*'([^']*)'")
route_re = re.compile(r"\$r->(get|post|put|delete)\(\s*'([^']*)'")

# 用括号深度跟踪 group 嵌套
depth = 0
group_depth = []

for raw in lines:
    line = raw.strip()
    if line.startswith("//") or line.startswith("*"):
        continue

    gm = group_re.search(line)
    if gm:
        prefix_stack.append(gm.group(1))
        group_depth.append(depth)

    rm = route_re.search(line)
    if rm:
        method = METHOD_MAP[rm.group(1)]
        prefix = "".join(prefix_stack)
        path = (prefix + rm.group(2)).rstrip("/")
        path = re.sub(r"\{[a-zA-Z_][a-zA-Z0-9_]*\}", "{id}", path)
        be_routes.add((method, path))

    depth += line.count("(") - line.count(")")
    while group_depth and depth <= group_depth[-1]:
        group_depth.pop()
        prefix_stack.pop()

# ---------------- 对比 ----------------
missing = sorted(fe_calls - be_routes)      # 前端调用但后端没有 → 会 404
unused = sorted(be_routes - fe_calls)       # 后端注册但前端没调

print("前端调用 %d 个 / 后端注册 %d 个\n" % (len(fe_calls), len(be_routes)))

if missing:
    print("\033[31m[前端调用但后端未注册] %d 个 —— 会导致页面报错\033[0m" % len(missing))
    for m, p in missing:
        print("  %-7s %s" % (m, p))
else:
    print("\033[32m[OK] 前端调用的每个接口后端都已注册\033[0m")

if unused:
    print("\n\033[33m[后端注册但前端未调用] %d 个\033[0m" % len(unused))
    for m, p in unused:
        print("  %-7s %s" % (m, p))

sys.exit(1 if missing else 0)
