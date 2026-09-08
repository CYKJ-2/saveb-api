# SAVEB API — 构建与部署配置

本目录集中存放所有可被发布的构建期配置：Dockerfile、环境变量、nginx、初始化脚本。

真实环境配置不提交 Git。首次下载源码后，复制 `build/env/.local.env.example` 为 `.local.env`，或复制 `.prod.env.example` 为 `.prod.env`，再填写数据库密码、APP_KEY 和 Collector 令牌。模板中的域名和密码占位值不能直接用于生产环境。本地已有配置无需覆盖。

---

## 文件清单

```
build/
├── Dockerfile              PHP 8.3 FPM 运行时镜像（含 pdo_pgsql / redis 扩展）
├── env/
│   ├── .local.env          本地开发（笔记本 / Win+Mac）环境变量
│   └── .prod.env           生产（Linux 服务器）环境变量
├── nginx/
│   ├── nginx.local.conf    本地 nginx：localhost，HTTP，无 SSL
│   └── nginx.prod.conf     生产 nginx：HTTPS、HSTS、限流、SSL 模板
└── README.md               （本文档）
```

---

## 本地 vs 生产 — 关键差异

| 配置项 | 本地开发（笔记本） | 生产（服务器） |
|---|---|---|
| 触发命令 | `start.bat` 或 `docker compose -f docker-compose.yml -f docker-compose.local.yml up -d` | `docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file build/env/.prod.env up -d --build` |
| Compose 文件 | `docker-compose.local.yml` | `docker-compose.prod.yml` |
| 环境变量 | `build/env/.local.env` | `build/env/.prod.env` |
| 宿主端口 | 8080 / 5433 / 6380（避开 saveb-erp） | 不暴露，容器间 Docker 网络互通 |
| 源码挂载 | `.:/var/www/html`（热重载） | 不挂载，**镜像即应用** |
| 资源限制 | 不设 | `cpus` / `memory` 限制 |
| `restart` | `unless-stopped` | `always` + `restart_policy` |
| nginx | `nginx.local.conf` | `nginx.prod.conf`（含 HTTPS / HSTS / 限流） |
| 队列 worker | 复用 app 容器（手动启动） | 独立 `worker` service |
| `APP_DEBUG` | `true` | `false` |
| `LOG_LEVEL` | `debug` | `warning` |
| `composer install` | 任意 | `--no-dev --optimize-autoloader` |

---

## 部署到生产服务器的标准流程

```bash
# 1. 服务器拉取代码
git pull origin main

# 2. 重新构建应用镜像（重要：服务器不挂载源码！）
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
               --env-file build/env/.prod.env \
               build --no-cache app

# 3. 启动所有服务
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
               --env-file build/env/.prod.env \
               up -d

# 4. 跑迁移（首次或升级后）
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
               exec app php artisan migrate --force

# 5. 优化（Laravel 生产优化）
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
               exec app php artisan config:cache
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
               exec app php artisan route:cache

# 6. nginx 切到生产配置
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
               exec nginx nginx -s reload
```

---

## 添加新服务的示例

假如要加一个 Meilisearch 服务：

1. 在 `docker-compose.yml` 的 `services:` 节添加共享定义
2. 如果本机需要暴露端口（Navicat / 浏览器测），在 `docker-compose.local.yml` 加 `ports:`
3. 如果服务器需要限内存，在 `docker-compose.prod.yml` 加 `deploy.resources.limits`
4. 在 `build/env/.local.env` 和 `.prod.env` 添加环境变量（如 `MEILISEARCH_URL`）
