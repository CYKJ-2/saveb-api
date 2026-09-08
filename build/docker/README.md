# SAVEB API — Docker Compose 配置

本目录集中管理 **本地开发** vs **生产服务器** 的 Docker Compose override 文件。

---

## 文件结构

```
saveb-api/                            ← 项目根
├── docker-compose.yml                ← 共享服务定义（base）
├── .env                              ← 符号链接 → build/env/.local.env（本地）
│                                       或符号链接 → build/env/.prod.env  （服务器）
├── build/
│   ├── docker/
│   │   ├── docker-compose.local.yml  ← 本地 override（暴露端口、热重载）
│   │   ├── docker-compose.prod.yml   ← 生产 override（限资源、不暴露端口、worker 长驻）
│   │   └── README.md                 ← （本文档）
│   ├── env/
│   │   ├── .local.env                ← 本地环境变量
│   │   └── .prod.env                 ← 生产环境变量
│   ├── nginx/
│   │   ├── nginx.local.conf
│   │   └── nginx.prod.conf
│   └── Dockerfile
└── ...
```

---

## .env 符号链接设计

### 为什么用符号链接？

- Laravel 期望项目根下有 `.env` 文件（这是它的约定）
- 但我们不希望 `.env` 跟随 git 提交
- 通过符号链接：
  - 本机指向 `build/env/.local.env` → 启动时本地配置生效
  - 服务器指向 `build/env/.prod.env` → 启动时生产配置生效
- 两个环境都使用**完全相同的命令**（`docker compose -f docker-compose.yml -f ... up`），不需要改任何东西

### Windows 创建符号链接

```powershell
# 删除根目录现有 .env（如有）
Remove-Item .\.env -Force -ErrorAction SilentlyContinue

# 创建指向本地配置的目录符号链接（/D 表示目录符号链接）
cmd /c mklink /D .env build\env\.local.env
```

> **注意**：
> - `cmd /c mklink /D` 创建的是**目录符号链接**，可以保留整个文件内容
> - PowerShell 的 `New-Item -ItemType SymbolicLink` 在 Windows 容器内通常需要开发者模式
> - 用 cmd 的 mklink 最稳定，不需要管理员权限（创建普通符号链接到同分区）

### Linux 服务器创建符号链接

```bash
# 删除现有 .env
rm -f .env

# 创建符号链接指向生产配置
ln -s build/env/.prod.env .env
```

---

## 启动命令

无论在哪台机器上，命令都相同，差别是 `.env` 符号链接指向：

### 本地开发（笔记本）

```powershell
# 1. 一次性：连接 .env 到本地配置
cmd /c mklink /D .env build\env\.local.env

# 2. 启动
cd e:\wwwroot\saveb-api
docker compose -f docker-compose.yml -f build/docker/docker-compose.local.yml up -d --build
```

### 生产服务器（Linux）

```bash
# 1. 服务器拉取代码
cd /var/www/saveb-api
git pull origin main

# 2. 一次性：连接 .env 到生产配置
ln -sf build/env/.prod.env .env

# 3. 启动（生产 override）
docker compose -f docker-compose.yml -f build/docker/docker-compose.prod.yml up -d --build
```

> **不要** 再加 `--env-file build/env/.prod.env`：
> 因为 `.env` 已经通过符号链接提供了所有变量。`docker compose` 默认会自动读取 `.env`。

---

## 容器间网络互通

| 服务 | 容器名 | 端口（容器内） | 本地宿主端口 | 生产宿主端口 |
|---|---|---|---|---|
| app (php-fpm) | saveb-api-app | 9000 | 仅内部 | 仅内部 |
| nginx | saveb-api-nginx | 80 | **8080** | **SLB 转发 443** |
| postgres | saveb-api-postgres | 5432 | **5433** | 不暴露 |
| redis | saveb-api-redis | 6379 | **6380** | 不暴露 |
| worker | saveb-api-worker | — | — | 不暴露 |

> 本机端口 5433/6380 避开 saveb-erp 主线产品的 5432/6379，方便同时运行多个项目。

---

## 差异详细对比

| 配置维度 | docker-compose.local.yml | docker-compose.prod.yml |
|---|---|---|
| 宿主端口映射 | `8080:80`、`5433:5432`、`6380:6379` | 不暴露，容器间 Docker 网络互通 |
| 源码卷挂载 | `.:/var/www/html`（热重载） | 不挂载，**镜像即应用** |
| `restart:` | `unless-stopped` | `always` + `restart_policy` |
| 资源限制 | 不设 | `cpus` / `memory` 限制 |
| `composer install` | 任意（默认） | `--no-dev --optimize-autoloader`（Dockerfile 中） |
| nginx 配置文件 | `nginx.local.conf` | `nginx.prod.conf`（HTTPS / HSTS / 限流） |
| 队列 worker | 复用 app 容器手动启动 | 独立 `worker` service，长驻 `queue:work` |
| 临时存储 | bind mount 整个项目 | 命名卷 `app_storage`、`app_cache` |
| SQL 初始化 | Laravel migration | `build/sql/*.sql` 自动跑 |

---

## 部署到生产服务器的完整流程

```bash
# 1. 拉取代码
cd /var/www/saveb-api
git pull origin main

# 2. 仅第一次：链接 .env
ln -sf build/env/.prod.env .env

# 3. 重建应用镜像（服务器代码变更是通过 rebuild，不是 bind mount！）
docker compose -f docker-compose.yml -f build/docker/docker-compose.prod.yml build --no-cache app

# 4. 启动（容器已存在则 recreate，使其使用新镜像）
docker compose -f docker-compose.yml -f build/docker/docker-compose.prod.yml up -d

# 5. 跑迁移
docker compose -f docker-compose.yml -f build/docker/docker-compose.prod.yml exec app php artisan migrate --force

# 6. Laravel 生产优化
docker compose -f docker-compose.yml -f build/docker/docker-compose.prod.yml exec app php artisan config:cache route:cache view:cache
```

---

## 添加新服务（如 Meilisearch）

1. 在根目录 `docker-compose.yml` 的 `services:` 加共享定义
2. 如果本机需要暴露端口（Navicat / 浏览器调试），在 `build/docker/docker-compose.local.yml` 加 `ports:`
3. 如果服务器需要限内存，在 `build/docker/docker-compose.prod.yml` 加 `deploy.resources.limits`
4. 在 `build/env/.local.env` 和 `build/env/.prod.env` 添加对应变量
5. 在 `build/README.md` 顶部表格更新端口映射
