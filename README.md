# NewAnimeSYS

自建看番系统 · **v1** · 2026-09-14

> 一句话：dmhy RSS → 正则匹配 → qBittorrent 自动下载 → 同步入库 → 浏览器播放。

**详细架构见 [ARCHITECTURE.md](./ARCHITECTURE.md)。**

## 快速索引

| 想了解 | 去看 |
|---|---|
| 系统由哪些部件组成 | `ARCHITECTURE.md` §2 部署架构 / §3 整体架构 |
| 目录怎么组织 | `ARCHITECTURE.md` §4 目录结构 |
| 数据怎么流动 | `ARCHITECTURE.md` §5 核心业务流程 |
| 为什么这么设计 | `ARCHITECTURE.md` §6 关键技术决策 |
| 数据库有哪些表 | `ARCHITECTURE.md` §7 数据模型 |
| 怎么部署/运维 | `ARCHITECTURE.md` §9 运维机制 |
| 还有什么没做 | `ARCHITECTURE.md` §10 已知限制与待办 |

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
