# NewAnimeSYS 架构说明（v1 · 2026-09-14）

自建看番系统：dmhy RSS → 正则匹配 → qBittorrent 自动下载 → 同步入库 → 浏览器播放。

---

## 1. 技术栈

| 层 | 选型 |
|---|---|
| 前端 | Vue 3 + Vite（SPA，无 UI 框架，手写浅蓝扁平设计系统） |
| 后端 | PHP 8.5（无框架，手写 Controller / Service / Core）+ MySQL 8.4 |
| Web | nginx 1.28 + php8.5-fpm |
| 下载 | qBittorrent 5.1.4（WebUI API，127.0.0.1:8080） |
| 媒体 | ffmpeg / ffprobe（remux + 字幕抽取） |
| 进程 | anime-daemon（常驻，5 个循环任务） |
| HTTPS | Let's Encrypt + certbot 4.0.0（自动续期） |
| 邮件 | 阿里云 DirectMail SMTP（smtpdm.aliyun.com:465 SSL） |

## 2. 部署架构（单机）

```
主机 <server-ip>（阿里云，Ubuntu 26.04，2C 3.6G）
├── nginx          443/80，TLS 终止、静态、X-Accel 发文件、反代 PHP
├── php8.5-fpm     unix socket，跑 API
├── mysql          元数据库 anime_new
├── qbittorrent-nox@root   下载器（WebUI 仅本机）
├── anime-daemon   抓取/同步/发信/字幕/remux 五循环
└── certbot.timer  证书续期
```

| 路径 | 用途 |
|---|---|
| `/opt/anime-new/backend` | PHP 后端 |
| `/opt/anime-new/frontend-src` | 前端源码（构建机） |
| `/opt/anime-new/frontend/dist` | 前端产物（nginx 根） |
| `/opt/anime-new/deploy` | 构建脚本 |
| `/data/downloads/anime` | 视频文件（`remux/` 子目录放 MP4 产物） |
| `/data/anime-subs` | 字幕抽取产物 |

站点：**https://www.acgn.help**

## 3. 整体架构

```
                        ┌─────────────────────────────┐
   浏览器 (Vue SPA) ──▶ │  nginx  (www.acgn.help)     │
                        ├─────────────────────────────┤
                        │ /            → dist 静态    │
                        │ /api/*       → index.php    │
                        │ /stream/{id} → stream.php   │
                        │ /subtitle/*  → subtitle.php │
                        │ /protected/  → internal alias│
                        └──────┬──────────────┬───────┘
                               │ FastCGI      │ X-Accel
                        ┌──────▼──────┐  ┌────▼──────────────┐
                        │ PHP Service │  │ 文件系统           │
                        │ Controller  │  │ downloads/anime   │
                        └──┬───┬───┬──┘  └───────────────────┘
                           │   │   └────────▶ MySQL
                           │   └────────────▶ qBittorrent API
                           └────────────────▶ dmhy RSS（HTTP）
```

## 4. 目录结构

```
backend/
  src/Controller/   Anime · Me · Auth · Admin · AdminSetting
  src/Service/      Fetch · MatchEngine · AutoDownloader · Sync · MediaProbe
                    Remux · Subtitle · TitleParser · Qbittorrent · DmhyRss
                    Mail · User
  src/Core/         Database · Auth · Request · Response · Setting · Mailer
                    Log · Router · Http
  public/           index.php · stream.php · subtitle.php
  bin/              daemon.php · migrate.php · e2e.php · check_admin.php
                    extract_subs.php · send_mail.php
  migrations/       9 个迁移 + schema.sql（只管全新建库）
frontend/
  src/views/        Home · Browse · AnimeDetail · Player · Me · Login · Register
                    WishWall · ForgotPassword · ResetPassword · VerifyEmail
                    admin/  Overview · Animes · Rules · Feeds · Items · Downloads
                            Users · Invites · Settings · AdminWishes · AdminLayout
  src/api/          index.js（fetch 封装 + token + streamUrl/subtitleUrl）
  src/store/        auth.js · ui.js · site.js
  src/components/   AnimeCard 等
deploy/             build_frontend.sh
tools/              ssh_put.py · ssh_run.py · sshconn.py · check_routes.py · tunnel.py
```

## 5. 核心业务流程

### 5.1 采集 → 匹配 → 下载

```
daemon（每 fetch_interval_min 分钟）/ 手动「抓取」按钮
  └─ FetchService::run()      拉 dmhy RSS → 去重入 rss_item（status=0）
  └─ AutoDownloader::run()    候选 = status=0（pub_date DESC，默认 300 条）
        └─ MatchEngine::match() 逐条跑 match_rule
        └─ guardAndAdd()        四道护栏后加种到 qB
```

**护栏（唯一入口 `guardAndAdd`）**：分辨率下限 · 同番同集去重 · 每小时加种上限 · 类目就绪。任何新增加种入口都必须走它。

**三个加种入口**：`run()`（daemon/手动抓取）、`downloadForAnime()/downloadForRule()`（保存规则后 + 后台按钮）、`downloadOne()`（单条强制，不套护栏）。

### 5.2 下载完成 → 入库

```
daemon（每 120s）
  └─ SyncService    查 qB「已完成」任务 → 匹配 rss_item（infohash 对账）
        └─ MediaProbe 探测编码（vcodec/acodec/resolution）
        └─ 入库 episode（file_path 指向 downloads/anime）
```

**infohash 一律 40 位小写 hex**：dmhy 磁力是 Base32（32 字符），qB 只认 hex，中间必须过 `TitleParser::normalizeInfohash()`，否则 rss_item 与 qB 任务永远对不上。

### 5.3 播放（双通路）

```
Player.vue → GET /api/episodes/{id}
  └─ playbackPlan 判定
       ├─ 通路 A（首选）：episode.remux_path 非空 → mode=direct
       │    <video src="/stream/{id}?token=..."> → nginx 发 MP4（Range 206）
       │    浏览器原生解码 HEVC Main10（硬件解码），音频时间戳连续
       └─ 通路 B（回退）：仅原始 MKV → mode=remux
            playsvideo 前端 demux MKV → MSE，segment 边界有音频 gap
```

### 5.4 服务端 remux（音频 gap 根治）

```bash
ffmpeg -c copy -tag:v hvc1 -f mp4 -movflags +faststart
```

- 零转码换容器（617MB 约 8.7s），产物 `{download_root}/remux/{episode_id}.mp4`
- **触发**：首次播放同步 remux + daemon 120s 轮询兜底
- `-tag:v hvc1` 关键：参数集写进 `hvcC` box，否则 Chrome 原生播失败
- 根因：playsvideo 按关键帧切 ~4s segment、音频按 endSec 硬切 → 交界时间戳跳变 → 周期性音频 gap

### 5.5 字幕（服务端抽取）

```
SubtitleService::extract()  ffmpeg -map 0:s:N -c:s webvtt（只 demux，0.48s/集）
  └─ episode_subtitle 表（uk: episode_id + track_index）
  └─ 分发 /subtitle/{id}/{track}.vtt?token=...（ETag 304）
  └─ 前端原生 <track :src> 多轨挂载
```

### 5.6 用户体系

```
注册 → email_token(verify) + MailService::verifyEmail 入队 → mail_queue
     → daemon 60s 消费 → SMTP 发出
     → 点链接 /api/auth/verify-email → status 0 → 1
登录 → HMAC-SHA256 无状态令牌（Authorization: Bearer 或 ?token=）
     → 每次请求回查库（封禁/改权限即时生效）
```

## 6. 关键技术决策

| 决策 | 原因 |
|---|---|
| 服务端 remux MP4 优先 | 根治 playsvideo 的音频 gap；零转码成本低 |
| playsvideo 仅作回退 | SharedArrayBuffer 需 HTTPS / Chrome PTS 缺陷，不作为主路径 |
| 字幕服务端抽取 | 前端内嵌扫描约 1.26x 实时（一集 19 分钟），不可用 |
| X-Accel-Redirect 发视频 | PHP 只鉴权，文件由 nginx 零拷贝发出（支持 Range） |
| 无状态 HMAC 令牌 | 可走 query（外部播放器直接打开），且回查库保证即时封禁 |
| 迁移文件 + migrate.php | 不手写 ALTER，`schema_migration` 表跟踪 |
| 密码字段掩码 `__SAVED__` | 读写不泄露，回传掩码表示"不修改" |
| 关键配置空值不覆盖 | 前端整表回传，空值跳过防误清空（SMTP 等） |

## 7. 数据模型

| 分组 | 表 |
|---|---|
| 用户 | `user` · `email_token` · `invite_code` |
| 内容 | `anime` · `episode` · `episode_subtitle` |
| 采集 | `rss_feed` · `rss_item` · `match_rule` |
| 个人 | `user_favorite` · `user_history` · `play_view` |
| 互动 | `wish`（许愿墙） |
| 系统 | `setting` · `mail_queue` · `job_log` · `schema_migration` |

## 8. API 概览

| 权限 | 路由 |
|---|---|
| 公开 | `/api/home` · `/api/animes` · `/api/animes/{id}` · `/api/episodes/{id}` · `/api/search` · `/api/wishes` |
| 注册/登录 | `/api/auth/{config,register,login,verify-email,resend-verify,forgot-password,reset-password}` |
| user | `/api/me/*` · `/api/episodes/{id}/subtitles/extract` · `POST /api/wishes` |
| admin | `/api/admin/{overview,animes,rules,feeds,items,torrents,wishes,users,invites,settings,logs,mails}` |

## 9. 运维机制

| 项 | 说明 |
|---|---|
| 数据库迁移 | `php bin/migrate.php [--list]` |
| 端到端验收 | `php bin/e2e.php http://127.0.0.1`（105 项，自清理） |
| 重建前端 | `bash deploy/build_frontend.sh` |
| 重启常驻进程 | `systemctl restart anime-daemon`（**改 Service 代码后必须重启**） |
| 日志 | `backend/storage/logs/`（daemon / sync / autodl / error / auth） |
| 路由核对 | `tools/check_routes.py` |

**daemon 循环**：抓取（`fetch_interval_min` 分钟，默认 30）· 同步 120s · 发信 60s · 字幕 120s · remux 120s。

## 10. 已知限制与待办

- **磁盘清理未做**：30GB 盘，`remux` 后 MKV + MP4 并存占盘翻倍；总览有水位告警但无自动清理策略
- **单机架构**：无冗余，主机故障即全站不可用
- **播放依赖客户端 HEVC 硬解**：Chrome 可，Firefox/Chromium 无 HEVC → 只能回退通路 B
- **邮件依赖第三方 SMTP**：阿里云 DirectMail，需维护发信地址与授权
- **未做 CDN / 存储分离**：视频与 Web 同机，带宽受单机限制

---

## 版本信息

- 版本：v1
- 日期：2026-09-14
- 归档：`NewAnimeSYS-v1.tar.gz`
- 恢复：前端 `npm install && npm run build`；后端无 composer 依赖，直接跑
