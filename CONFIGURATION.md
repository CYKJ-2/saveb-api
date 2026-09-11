# 配置入口：只维护 .env 和 nginx.conf

本地开发、Linux 生产部署都使用项目根目录的 **`.env`** 和 **`nginx.conf`**。不再使用 `build/env/`，也不再通过符号链接切换环境。切换 Git 分支只切换源码，`.env` 是每台机器独立的私有配置，不会随分支自动切换。

| 文件 | 用途 | 日常是否修改 |
|---|---|---|
| `.env` | 环境、数据库、Redis、访问地址、Collector、账号密钥 | 是；本地与服务器各维护自己的值，不进 Git |
| `nginx.conf` | API 的 HTTP/PHP 转发配置 | 按需修改；本地/生产都挂载这一份 |
| `.env.example` | 唯一的可提交环境模板 | 新增环境参数时同步模板 |
| `docker-compose.yml` | 本地开发服务定义 | 通常不改 |
| `Dockerfile` | 共用 PHP 基础环境，development/production 两个构建阶段 | 通常不改 |
| `docker-compose.server.yml`、`docker-compose.infra.yml` | 生产应用及独立数据库/Redis定义 | 通常不改 |
| `docker/` | PHP 容器启动、上传参数和健康检查 | 通常不改 |

旧 `deploy/` 已移除，自动发布代码集中在 `automation/` 与 `.github/workflows/release.yml`，Docker 运行文件保留在根目录和 `docker/`。Laravel 自身的 `config/` 属于应用源码，也不需要按机器改动。

## 首次准备

仅在还没有 `.env` 时复制 `.env.example`，不要覆盖已有密钥和密码。根目录 `nginx.conf` 已提供默认值，一般可以直接使用。

Linux：

```bash
test -e .env || cp .env.example .env
chmod 600 .env
```

`.env.example` 默认给出 Linux 服务器配置，每组字段都有中文说明和本地差异；填完标记为【必填】的真实凭据后使用。本地 `.env` 与模板字段对齐，值分别维护，不能把本地文件直接覆盖到服务器。

已移除不被当前程序读取的 `APP_TIMEZONE`、`BROADCAST_CONNECTION`、`UPLOAD_DISK`、`MAX_UPLOAD_SIZE`、`IMAGE_REGISTRY`、`IMAGE_TAG`。旧 `NGINX_PORT` 的有效值迁到 `API_PORT`；自动发布镜像由工作流注入。上传限制在 `docker/business-uploads.ini` 和 `nginx.conf` 设置。Laravel 当前时区在 `config/app.php` 固定为 UTC，删除无效的 APP_TIMEZONE 不改变现有日期处理行为。

本次整理保留了原有有效配置值，并将原始私有文件备份为 `.env.backup-时间戳`，备份同样被 Git/Docker 忽略。配置统一使用 UTF-8（无 BOM）和 LF 换行。本地和生产按下表核对同一个 `.env`：

| 参数 | 本地开发 | 生产服务器 |
|---|---|---|
| `APP_ENV` | `local` | `production` |
| `APP_DEBUG` | `true` | `false` |
| `API_PORT` | `8080` | `18088`（避开旧 ERP 的 18080/18081） |
| `APP_URL` | `http://localhost:8080` | 实际 API/后台 API 对外地址 |
| `FRONTEND_URL`、`CORS_ALLOWED_ORIGINS` | 实际本地 Admin 地址 | 实际生产 Admin 地址 |
| `DB_HOST` | `saveb-api-postgres` | `saveb-api-postgres` |
| `REDIS_HOST` | `saveb-api-redis` | `saveb-api-redis` |
| `SAVEB_COLLECTOR_URL` | `http://host.docker.internal:18085`，以本地实际端口为准 | `http://saveb-collector-api:8080` |
| `APP_KEY`、数据库/Redis密码、Collector Token | 保留当前本地值 | 使用生产值，不能保留模板占位值 |

API 的 `SAVEB_COLLECTOR_TOKEN` 必须与 Collector 的 `SAVEB_API_TOKEN` 一致。API 与 Collector 连接同一业务库。

`APP_KEY` 是应用密钥，不是数据库密码。已有数据时保留原值；全新空库首次生成后长期保存，启动和发布脚本不会替你重置它。`RBAC_SEED_ADMIN_PASSWORD` 仅用于首次创建生产管理员，不会覆盖已有账号密码。

`nginx.conf` 是容器内的 `server { ... }` 配置，不是宿主机 `/etc/nginx/nginx.conf`。内部 `listen 8080`、`fastcgi_pass app:9000` 通常不改；宿主机端口通过 `.env` 的 `API_PORT` 设置。默认仍绑定 127.0.0.1；设置 APP_URL 或 server_name 不会自动开放内网/公网端口，也不会生成 TLS 证书。Admin 的网页入口属于 saveb-admin。

## 本地启动和更新

Windows 使用 `start.bat` 或 `scripts/start-local.ps1`，Linux/macOS 使用 `bash start.sh`。不再组合 local/prod override 文件。

```bash
docker compose -f docker-compose.yml up -d --build
```

本地挂载源码与现有 dev vendor。全新开发机需要安装含 dev 依赖的 Composer 包；也可通过开发镜像执行 `docker compose run --rm --no-deps app composer install`。数据库首次初始化见 README，启动本身不会初始化或迁移已有库。

修改 `.env` 后，通过 `docker compose up -d --no-deps app` 让容器重新读取环境；若之前手动开启了 Laravel 配置缓存，再执行 `docker compose exec app php artisan config:clear`。

修改 `nginx.conf` 后先检查，再重载：

```bash
docker compose exec nginx nginx -t
docker compose exec nginx nginx -s reload
```

若编辑器通过替换文件保存导致单文件挂载仍看到旧内容，使用 `docker compose up -d --no-deps --force-recreate nginx` 重新挂载。修改 API_PORT 等端口参数也需要重新创建 nginx 容器。

本地项目名固定 `saveb-api`，保留 `saveb-api_postgres_data`、`saveb-api_redis_data`、`saveb-api_business_attachments`。不要改名、删除卷或执行 `down -v`。

## 生产启动和更新

生产只使用根目录 docker-compose.server.yml，不与本地 docker-compose.yml 合并。基础设施使用 docker-compose.infra.yml。首次数据和配置准备见 [SERVER-DEPLOY.md](SERVER-DEPLOY.md)，自动发布见 [AUTODEPLOY.md](AUTODEPLOY.md)。GitHub 云端构建镜像，服务器 runner 拉取并更新服务，日常不需要服务器手动构建。

旧 deploy/ 不恢复；当前 Actions 使用内置 GITHUB_TOKEN 访问 GHCR，需注册内网 runner 并设置仓库变量 DEPLOY_ENABLED。发布支持恢复上一健康镜像，但不自动回滚数据库。docker/entrypoint.sh、docker/start-local.sh、docker/health.php 和 docker/business-uploads.ini 是容器运行所需文件，不属于发布工具。

nginx.conf 仍从服务器根目录只读挂载，.env 独立保存在每台机器上。数据库/附件导入、生产空库初始化、管理员和备份是首次部署准备，不能用修改两个配置文件代替。local:database-init 仍只允许本地空库。

## 旧配置迁移记录

本地 .env 符号链接已转为普通文件，值未修改；旧私有配置备份在 .config-backup/before-config-unify/，不提交 Git、不进入镜像。build/ 和旧 local/prod Compose 链接已移除。

其他机器若还使用指向 build/env 的旧 .env 链接，先保存链接目标内容为普通 .env 再更新源码。保留 APP_KEY、密码和附件，不能直接丢弃这些配置。

当前本地 API 启动文件引用 docker/start-local.sh；仅源码路径调整，不改变现有数据库、Redis、附件卷名称。旧业务迁移辅助 docker-compose.modules.yml 保留，不用于新生产部署。
