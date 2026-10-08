#!/bin/bash
# 初始化数据库 + 生成 config.php（在新服务器上跑）
set -e

DBPASS=$(openssl rand -hex 16)
SECRET=$(openssl rand -hex 32)

echo "### 1. 创建数据库与用户"
mysql -e "CREATE DATABASE IF NOT EXISTS anime_new CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS 'anime'@'localhost' IDENTIFIED BY '${DBPASS}';"
mysql -e "ALTER USER 'anime'@'localhost' IDENTIFIED BY '${DBPASS}';"
mysql -e "GRANT ALL PRIVILEGES ON anime_new.* TO 'anime'@'localhost'; FLUSH PRIVILEGES;"

echo "### 2. 写 config.php"
mkdir -p /opt/anime-new/backend/config
cat > /opt/anime-new/backend/config/config.php <<EOF
<?php
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'anime_new',
        'user'    => 'anime',
        'pass'    => '${DBPASS}',
        'charset' => 'utf8mb4',
    ],
    'secret'    => '${SECRET}',
    'debug'     => false,
    'timezone'  => 'Asia/Shanghai',
    'cors'      => [],
];
EOF
chmod 640 /opt/anime-new/backend/config/config.php
chown www-data:www-data /opt/anime-new/backend/config/config.php

echo "### 3. 导入 schema"
if [ -f /opt/anime-new/backend/migrations/schema.sql ]; then
  mysql anime_new < /opt/anime-new/backend/migrations/schema.sql
  echo "schema.sql 已导入"
else
  echo "!! 找不到 schema.sql"
fi

echo "### 4. 表清单"
mysql anime_new -e "SHOW TABLES;" | tr '\n' ' '
echo ""
echo "### DB_PASS=${DBPASS}"
echo "### SECRET=${SECRET}"
echo "### INIT_DONE"
