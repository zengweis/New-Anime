# NewAnimeSYS

自建看番系统 

本项目最早可以追溯到去年写的基于s3的自动追番项目，当时的实现方式特别草率，先将动漫通过qbittorrent——rss订阅功能下载到本地，再由定时执行的python脚本实现上传以及清理本地数据，没有任何校验等（也就是说任何人都能来刷爆你的s3流量（千万不要学我！！））。而且更傻逼的是，关于页面更新也是python定时执行拉取s3对象列表，如果动漫数量较多，且拉取频率较快，很容易干爆cf R2存储的免费额度。
并且因高考备考等原因项目被遗弃，现在重拾并优化方案。
懒得写readme了，看ai写的吧，想看人工去博客[链接]（https://tsunako.fun/2026/09/20/Anime-New-%E5%A4%9A%E7%A7%9F%E6%88%B7%E7%9A%84%E8%BF%BD%E7%95%AA%E7%B3%BB%E7%BB%9F）

RSS → 正则匹配 → qBittorrent 自动下载 → 同步入库 → 浏览器播放。

**详细架构见 [ARCHITECTURE.md](./ARCHITECTURE.md)。**


## 目录

```
backend/    PHP 8.5 后端（无框架）
frontend/   Vue 3 + Vite 前端
deploy/     构建脚本
tools/      本地部署辅助脚本
```

## 站点

https://www.acgn.help

## 恢复

- 前端：`cd frontend && npm install && npm run build`
- 后端：无 composer 依赖，PHP 8.5 + 扩展（pdo_mysql / curl / mbstring）直接跑
- 数据库：`backend/migrations/schema.sql` 建库，或逐条跑 `migrations/*.sql`
