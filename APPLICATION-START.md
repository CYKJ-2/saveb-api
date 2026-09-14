# 数据库初始化后：启动 API、Admin 和 Collector

当前流程：**本地提交并 push → 服务器 git pull → 服务器 docker build → Docker Compose 启动和健康检查**。不需要本地构建镜像、GHCR 登录或 Actions runner。生产 Compose 只负责运行，构建命令显式执行；不用再增加 Compose 文件。

先将三个项目本次修改分别提交、推送到 GitHub，然后在服务器执行以下步骤。`.env` 不提交；服务器已有 `nginx.conf` 如有本地修改，应先核对并保留，遇到 git pull 冲突不要直接覆盖配置。

以下使用 ECR Public 的官方基础镜像地址，避开之前服务器 Docker Hub 超时的问题。服务器仍需联网下载基础镜像和 apt/Composer/npm/pip 依赖。Dockerfile 的默认地址保持原样，通过构建参数切换，不修改 Docker 全局配置。

命令中的 `RELEASE_IMAGE` 是服务器自己生成的镜像标签。每次进入项目目录重新设置，构建成功后才更新容器。首次构建可能耗时较长，后续会复用构建缓存。以下命令以 PostgreSQL、两个 Redis 已健康、API 数据库已初始化为前提。

## 1. 配置浏览器入口

服务器 saveb-admin/.env：

```dotenv
ADMIN_BIND_IP=192.168.11.84
ADMIN_PORT=13000
DEPLOY_NETWORK=saveb-production
```

服务器 saveb-api/.env：

```dotenv
APP_URL=http://192.168.11.84:13000
FRONTEND_URL=http://192.168.11.84:13000
CORS_ALLOWED_ORIGINS=http://192.168.11.84:13000
```

这三个 API 地址是访问入口，不修改 DB_HOST 或 API/Collector 容器内端口。Admin 的 nginx.conf 保留 listen 80 和 saveb-api-web:8080，API 保留 listen 8080 和 app:9000，Collector 没有 Nginx。全部服务使用 saveb-production 网络。

## 2. 启动 API

```bash
cd /home/admin_chen/www/saveb-api
git pull --ff-only origin main &&
export RELEASE_IMAGE="saveb-api:server-$(git rev-parse HEAD)" &&
docker build --target production \
  --build-arg PHP_IMAGE=public.ecr.aws/docker/library/php:8.3-fpm-bookworm \
  --build-arg COMPOSER_IMAGE=public.ecr.aws/docker/library/composer:2 \
  -t "$RELEASE_IMAGE" . &&
docker compose -f docker-compose.server.yml up -d --no-build --pull never --wait
curl -f http://127.0.0.1:18088/up
```

此时启动 app、web、worker，不重复执行已完成的 server:database-init。构建或启动失败时，先处理报错再继续。

## 3. 启动 Admin，在浏览器登录

```bash
cd /home/admin_chen/www/saveb-admin
git pull --ff-only origin main &&
export RELEASE_IMAGE="saveb-admin:server-$(git rev-parse HEAD)" &&
docker build \
  --build-arg NODE_IMAGE=public.ecr.aws/docker/library/node:22-bookworm-slim \
  --build-arg NGINX_IMAGE=public.ecr.aws/docker/library/nginx:stable-alpine \
  -t "$RELEASE_IMAGE" . &&
docker compose up -d --no-build --pull never --wait
curl -f http://192.168.11.84:13000/healthz
```

本地浏览器打开：**http://192.168.11.84:13000/dashboard/overview**。首次未登录会跳转登录页，初始账号 super_admin，初始密码 123456，首次登录修改密码。已改过密码的账号继续用新密码。

同内网同事也访问这个地址；不需要 SSH 隧道。浏览器通过 Admin 的 /api 代理访问后端，无需直接连接 18088 或 18085。如打不开，本地 PowerShell 执行 `Test-NetConnection 192.168.11.84 -Port 13000`，并检查服务器 `docker compose ps` 的端口是否为 192.168.11.84:13000->80。默认 127.0.0.1 绑定无法从其他电脑访问。

## 4. Collector 先安装自己的表并启动接口

此前 API 初始化不会创建 collector schema，需执行 Collector SQL 迁移。先核对服务器 Collector .env：

- SAVEB_DATABASE_URL 指向同一个 API PostgreSQL，主机 saveb-api-postgres、容器内端口 5432；Navicat 的宿主机映射端口 5433 不用于容器之间。
- SAVEB_REDIS_URL 使用 redis://saveb-collector-redis:6379/0，CELERY_BROKER_URL 和 CELERY_RESULT_BACKEND 分别使用该 Redis 的 /1、/2（完整配置名均以 SAVEB_ 开头）。
- SAVEB_API_TOKEN 与 API 的 SAVEB_COLLECTOR_TOKEN 相同，SAVEB_PUBLISH_API=true，DEPLOY_NETWORK=saveb-production。
- 来源账号和物流密钥按原私有配置保留，密钥未配置时不启用物流自动查询。

```bash
cd /home/admin_chen/www/saveb-collector
mkdir -p config
git pull --ff-only origin main &&
export RELEASE_IMAGE="saveb-collector:server-$(git rev-parse HEAD)" &&
docker build \
  --build-arg PYTHON_IMAGE=public.ecr.aws/docker/library/python:3.12-slim-bookworm \
  -t "$RELEASE_IMAGE" . &&
docker compose -f docker-compose.server.yml --profile tools run --rm --no-deps --pull never \
  migrate python scripts/migrate.py &&
docker compose -f docker-compose.server.yml up -d --no-build --pull never --wait api
curl -f http://127.0.0.1:18085/ready
```

预期 /ready 返回 status=ready。这一步只启动 Collector API，不启动任务 worker 和 beat；管理后台不依赖自动采集就可以先登录。/ready 仅检查数据库中的 Collector 表版本，不能替代完整采集验收。

## 5. 数据和规则准备后，启动任务与定时采集

首次仅建基础表时，public.system_state 通常没有 legacy_dashboard_context 规则上下文；当前 Collector 的 load_context 会要求 siteRules 和有效汇率上下文。需要按原业务配置迁入规则/汇率，或者提供私有规则和汇率文件并配置 SAVEB_RULES_FILE/SAVEB_RATES_FILE。不要生成空规则来绕过检查，也不要仅凭 /ready 就开启采集。

准备好业务迁移窗口、站点规则/汇率及来源账号后，在 Collector 目录、RELEASE_IMAGE 仍为对应镜像的终端执行：

```bash
docker compose -f docker-compose.server.yml --profile tools run --rm --no-deps --pull never \
  migrate python scripts/preflight.py &&
docker compose -f docker-compose.server.yml up -d --no-build --pull never --wait api worker history maintenance logistics
```

首次先启动接口和任务执行器，从 Admin 首页点击“采集当天数据”，确认采集成功后，在同一个终端开启定时调度：

```bash
docker compose -f docker-compose.server.yml up -d --no-build --pull never beat
```

beat 是定时调度进程，启动后将按数据库配置自动投递任务；同一生产环境只运行一个 beat。自动采集间隔在 Admin 的系统管理 → 采集管理设置，手动采集可从首页或采集管理页面触发。上述分步启动适用于尚未运行 beat 的首次安装，不会停止已运行的 beat。

Collector 服务状态和日志：

```bash
docker compose -f docker-compose.server.yml ps
docker compose -f docker-compose.server.yml logs --tail=100 api worker beat
```

如果 preflight 失败，停止在此步处理规则、连接或账号配置，保留 API/Admin 正常访问，不反复启动自动任务。

## 6. 后续发布和回退

本地分别在有修改的项目提交并 push；服务器进入对应目录，重复上面的 git pull、设置镜像标签、docker build 和 up 命令即可。API 有新增数据库迁移时，构建成功后、更新服务前执行 `docker compose -f docker-compose.server.yml --profile tools run --rm --no-deps --pull never migrate php artisan migrate --force`。数据库迁移前备份数据库；不要再次执行首次空库初始化。

Collector 后续版本先运行 SQL 迁移，再做 preflight，最后更新所有已启用的 worker 和 beat；当前尚未配置采集规则时仍只更新 api。不要只更新接口而让旧 worker 长期运行。

若仅修改服务器 `.env`，使用对应的 `up -d --no-build --pull never --force-recreate --wait` 重建容器使配置生效（Collector 尚未启用任务时末尾加 `api`）；Nginx 配置使用对应 Compose 的 `restart web` 后再检查健康。新终端需要重新设置 RELEASE_IMAGE 为当前使用的本机镜像，不能沿用另一个项目的值。

每次发布前记录 `docker compose -f docker-compose.server.yml images`（Admin 使用 `docker compose images`）中的旧标签。回退时将 RELEASE_IMAGE 设置为该项目旧的、仍保留在服务器的镜像，再执行对应 up 命令和健康检查。镜像回退不回退数据库；有数据库结构变化时需检查兼容性。

GitHub 已有工作流可以保留以后使用。`DEPLOY_ENABLED=false` 只跳过部署，不会停止云端测试或构建；如果暂时完全不使用 Actions，在各仓库 Actions 页面选中 Build and deploy production，通过菜单 Disable workflow 暂停工作流。当前手动部署不依赖其执行结果。
