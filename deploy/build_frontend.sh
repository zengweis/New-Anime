#!/usr/bin/env bash
# anime-new 前端构建脚本
set -e

NODE_BIN=/opt/nodejs/bin
SRC=/opt/anime-new/frontend-src
OUT=/opt/anime-new/frontend/dist
export PATH="$NODE_BIN:$PATH"
# 注意：不能在 install 前设 NODE_ENV=production，否则 devDependencies（vite）会被跳过
unset NODE_ENV

echo "==> node $(node -v) / npm $(npm -v)"

cd "$SRC"

# 用国内镜像，HK 节点也稳
npm config set registry https://registry.npmmirror.com --location project 2>/dev/null || true

echo "==> npm install"
# 每次都跑：旧逻辑在 node_modules/.bin/vite 已存在时直接跳过，
# 会导致 package.json 里新增的依赖（如 playsvideo）永远装不上。
npm install --include=dev --no-audit --no-fund

echo "==> vite build"
rm -rf dist
NODE_ENV=production npm run build

echo "==> 发布 dist -> $OUT"
mkdir -p "$OUT"
rm -rf "${OUT:?}"/*
cp -r dist/. "$OUT"/

chown -R www-data:www-data /opt/anime-new/frontend
find /opt/anime-new/frontend -type d -exec chmod 755 {} \;
find /opt/anime-new/frontend -type f -exec chmod 644 {} \;

echo "==> 产物清单"
ls -lh "$OUT"
du -sh "$OUT"
echo BUILD_OK
