#!/bin/bash
# 在新服务器上构建前端并发布到 frontend/dist
set -e

cd /opt/anime-new/frontend-src

echo "### npm install"
npm install --no-audit --no-fund 2>&1 | tail -8

echo "### npm run build"
npm run build 2>&1 | tail -15

echo "### 发布到 frontend/dist"
rm -rf /opt/anime-new/frontend/dist
mkdir -p /opt/anime-new/frontend/dist
cp -r dist/* /opt/anime-new/frontend/dist/
echo "产物："
ls -la /opt/anime-new/frontend/dist/
echo "### BUILD_DONE"
