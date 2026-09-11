# 服务器配置与首次数据准备

当前采用 **本地 push → GitHub Actions 云端测试/构建 → GHCR → 内网 runner 拉镜像部署**。自动发布入口、runner 注册、GitHub 权限、首次启用及回滚见 [AUTODEPLOY.md](AUTODEPLOY.md)。服务器不执行应用镜像构建，日常发布也不用手动 git pull。

2026-09-10 已根据用户提供的 df/lvs 确认服务器扩容成功：根文件系统约 588 GB，可用约 507 GB。代码配置已准备不等于服务器已经部署成功；首次镜像构建、数据库迁移及访问入口仍需实际验证。

## 首次初始化：不依赖本地数据

全新环境填写数据库等 `.env` 配置后，通过 `server:database-init --force` 安装全部表、增量迁移和源码内置的基础角色/权限，并创建管理员。无需导出本地 RBAC 或上传快照。完整 clone、Docker 与服务器命令见 [RBAC-BOOTSTRAP.md](RBAC-BOOTSTRAP.md)。API/Admin 可先运行，旧业务和附件另行迁入，Collector 按数据准备进度启用。

## 文件与配置

| 项目 | 构建文件 | 服务器 Compose | 需要维护的配置 |
|---|---|---|---|
| saveb-api | 根目录 Dockerfile，production 阶段 | docker-compose.server.yml | 根目录 .env、nginx.conf |
| saveb-admin | 根目录 Dockerfile | docker-compose.yml | 根目录 .env、nginx.conf |
| saveb-collector | 根目录 Dockerfile | docker-compose.server.yml | 根目录 .env；config/ 可为空 |

API 的 docker/ 包含 PHP 容器启动、上传限制和健康检查；自动发布工具独立放在 automation/。API、Collector 本地开发继续使用各自原有 docker-compose.yml；不要把本地 Compose 与 server Compose 叠加。

三个项目现在都只提供根目录 .env.example。仅在没有真实 .env 时复制模板，不覆盖现有 APP_KEY、账号密码或服务 Token。服务器核对 APP_ENV=production、APP_DEBUG=false、API_PORT=18088，生产 Collector 地址使用 http://saveb-collector-api:8080。API 与 Collector 共用新业务数据库和相同服务 Token。

保留独立项目名 saveb-api-production、saveb-admin-production、saveb-collector-production、saveb-infra，以及新网络 saveb-production，不操作旧 ERP/禅道的容器或卷。API、Admin、Collector 默认仅绑定 127.0.0.1 的 18088、13000、18085；后台内网/域名入口需要另外配置，修改 APP_URL 不会自动开放监听地址。

## 本次服务器端口与数据迁移约定

| 项目 | 服务器根目录 .env 设置 | 宿主机监听 | 容器内 HTTP 端口 |
|---|---|---|---|
| saveb-admin | `ADMIN_PORT=13000` | `127.0.0.1:13000` | `80` |
| saveb-api | `API_PORT=18088` | `127.0.0.1:18088` | `8080` |
| saveb-collector | `COLLECTOR_PORT=18085` | `127.0.0.1:18085` | `8080` |

已有服务器 `.env` 中的端口值会覆盖 Compose 默认值，更新源码后也要核对该文件，服务器使用上表值。容器间继续使用 `saveb-api-web:8080`、`saveb-collector-api:8080`，无需将 nginx.conf 的内部端口改成宿主机端口。上述回环地址仅服务器自身可访问；尚未配置域名/对外反向代理，不能直接在开发电脑用这些地址访问服务器。

### 三个 .env 的首次填写

模板已统一为 Linux 生产示例，使用 UTF-8（无 BOM）编码；本地 .env 与模板字段一致，但各机器的值独立维护。服务器通常只需填写 API 的 APP_KEY、数据库密码、Redis 密码、共享 Token，以及 Collector 的同库 DSN、相同 Token、收单账号和密码，再核对实际访问入口。其他字段按模板中文说明保留默认值。Admin 没有业务密码，仅配置端口和网络。

旧文件中没有的新字段按模板补齐；已有 .env 不要直接覆盖。APP_TIMEZONE 不是当前 API 的有效配置，Laravel 时区仍由 config/app.php 决定。普通采集间隔在采集管理页面设置，不要添加已停用的 SAVEB_COLLECT_INTERVAL_MINUTES。

在服务器执行，已有 .env 时保留原文件，不显示其内容：

```bash
for app in saveb-api saveb-admin saveb-collector; do
  root="/home/admin_chen/www/$app"
  if [ ! -e "$root/.env" ] && [ ! -L "$root/.env" ]; then
    (umask 077; cp "$root/.env.example" "$root/.env") || break
  fi
done
mkdir -p /home/admin_chen/www/saveb-collector/config
```

分别使用 `nano /home/admin_chen/www/项目名/.env` 编辑。API 需核对：

| 参数 | 本次服务器值 |
|---|---|
| APP_ENV / APP_DEBUG | `production` / `false` |
| API_PORT / DEPLOY_NETWORK | `18088` / `saveb-production` |
| APP_URL / FRONTEND_URL / CORS_ALLOWED_ORIGINS | 暂均用 `http://127.0.0.1:13000`，通过文末 SSH 隧道验收；以后改真实入口 |
| APP_KEY | 保留对应新系统原密钥，不能留空或随意重置 |
| DB_CONNECTION / DB_HOST / DB_PORT | `pgsql` / `saveb-api-postgres` / `5432` |
| DB_DATABASE / DB_USERNAME / DB_PASSWORD | `saveb` / `saveb` / 实际数据库密码；已有生产卷时以原配置为准 |
| REDIS_HOST / REDIS_PORT / REDIS_PASSWORD | `saveb-api-redis` / `6379` / 实际 Redis 密码 |
| CACHE_STORE / SESSION_DRIVER / QUEUE_CONNECTION | 均为 `redis` |
| SAVEB_COLLECTOR_URL / SAVEB_COLLECTOR_ACCOUNT | `http://saveb-collector-api:8080` / `default` |
| SAVEB_COLLECTOR_TOKEN | 至少 32 字符的随机服务 Token，与 Collector 一致 |
| BUSINESS_ATTACHMENTS_ROOT | `/data/attachments` |

Collector 需核对：

| 参数 | 本次服务器值 |
|---|---|
| COLLECTOR_PORT / DEPLOY_NETWORK | `18085` / `saveb-production` |
| SAVEB_DATABASE_URL | `postgresql://saveb:实际密码@saveb-api-postgres:5432/saveb`，数据库名/用户名/密码与 API 一致 |
| SAVEB_REDIS_URL | `redis://saveb-collector-redis:6379/0` |
| SAVEB_CELERY_BROKER_URL | `redis://saveb-collector-redis:6379/1` |
| SAVEB_CELERY_RESULT_BACKEND | `redis://saveb-collector-redis:6379/2` |
| SAVEB_API_TOKEN / SAVEB_SOURCE_ACCOUNT | 与 API 的 Token 一致 / `default` |
| SAVEB_PUBLISH_API | `true` |
| SAVEB_DH_BASE_URL | `https://www.dh-order.com` |
| SAVEB_DH_USERNAME / SAVEB_DH_PASSWORD | 在服务器私有 .env 填写收单账号和密码；保留原字符，不提交 Git |
| SAVEB_DH_COOKIE | 使用账号登录时留空 |
| SAVEB_RULES_FILE / SAVEB_RATES_FILE | 留空，从已迁入的 API 规则数据加载 |
| SAVEB_LOGISTICS_ENABLED | 密钥未配置时为 `false` |
| SAVEB_AFTERSHIP_API_KEY / SAVEB_KUAIDI100_API_KEY | 暂留空 |

DSN 中密码含 `@`、`:`、`#`、`%` 等特殊字符时，需要 URL 编码。只对全新基础设施生成新密码，已有卷的数据库密码不会随 .env 自动改变。普通订单自动采集间隔保存在数据库中，通过 Admin 系统管理下的采集管理设置；SAVEB_PENDING_INTERVAL_MINUTES 是独立的历史 Pending 发现间隔。

Admin 只需 `ADMIN_PORT=13000`、`DEPLOY_NETWORK=saveb-production`。不要给三个 .env 添加固定 RELEASE_IMAGE，镜像由工作流按 digest 注入。

当前无需改 nginx.conf：API 保留 `listen 8080`、`fastcgi_pass app:9000`；Admin 保留 `listen 80` 和 `http://saveb-api-web:8080`。它们均为容器内配置，不能把这些端口改成宿主机的 18088/13000。Collector 不需要 nginx.conf。

首次上线由源码内置 RbacSeeder 创建基础角色、完整权限和初始管理员，默认密码为 `123456`，首次登录要求修改密码。API/Admin 可先运行空业务库；其他业务数据和附件之后从旧线上系统迁入。新环境不依赖任何本地个人账号、数据库 ID 或私有快照。

- 保留目标库 `users`、`roles`、`permissions`、`user_roles`、`role_permissions`、`api_tokens`、`audit_logs`；不以旧系统同名表覆盖账号、密码、菜单、角色和授权。
- 目标库 Laravel `migrations` 记录按目标结构核对并保留，不能用旧系统的迁移记录替换；`knex_migrations` 等旧框架元数据不当作业务数据直接套用。
- 迁移订单、人工调整、删除标记、采购、仓库、发票、PayPal、统计及附件等业务数据，实际执行前列出源表到目标表、字段和依赖的明确清单。旧库不存在的新系统表逐项确认保留或初始化，不能因源表缺失就清空目标表。
- 附件数据库记录和真实文件一起迁移；核对路径、数量及文件校验值。历史操作人 ID/UUID 与保留的用户不一定对应，先核对关联并制定映射或历史引用兼容方案，不自动改成管理员，不全局关闭外键。
- 先备份目标库及附件，再在独立临时库试导入。正式导入期间暂停新系统写入和 Collector；源库只读导出。导入前后对比 RBAC 内容、业务记录数/关键字段、序列和外键，通过后再启动采集。

此前本地导入经验见 saveb-api 的 `DATABASE-IMPORT-20260907.md`，其中表数量、外键处理及附件缺失情况只是当时的记录，不代表本次线上迁移结果。根目录历史 `copy-*.ps1` / `copy-*.sh` 不是本次生产迁移入口，部分脚本包含清表或级联逻辑，不能直接复用。当前配置变更没有执行任何线上数据迁移。

## 首次准备顺序

1. 本地验证并提交三个仓库到 main；部署开关 DEPLOY_ENABLED 暂为 false，让 GitHub 先完成云端测试与镜像构建。
2. 服务器三个现有仓库分别 `git pull --ff-only origin main` 获取一次配置和工具，保留真实 .env 和根目录 nginx.conf。遇到旧 .env 符号链接先保存内容为普通文件。
3. 服务器填写三个 .env（API 生产参数见 CONFIGURATION.md），Admin 设置 ADMIN_PORT=13000；Collector 创建 config/ 并填写来源账号、数据库 DSN 和服务 Token。API 与 Collector 共用同库，Redis 各自独立。
4. API 目录执行 `docker compose -f docker-compose.infra.yml config --quiet`，成功后 `docker compose -f docker-compose.infra.yml up -d --wait`，仅创建新生产基础设施。
5. 在空库执行 server:database-init --force，创建全部结构、基础权限和初始管理员。具体容器命令见 RBAC-BOOTSTRAP.md。
6. 按 AUTODEPLOY.md 注册 runner。基础初始化完成后先启用 API/Admin；旧业务、规则及来源配置完成后再启用 Collector。各项目就绪后分别创建 .deploy-ready 和启用 DEPLOY_ENABLED。
7. 在后台核对权限、历史业务、附件、采集间隔，再验证当天手动采集和自动任务。

## 配置保持与后续维护

.gitignore 排除真实 .env、配置备份、业务附件和发布状态。automation/、根 Dockerfile 和 Compose 必须提交。发布使用 runner 工作区的精确提交，并将 Compose 与镜像 digest 保存到服务器项目的 releases/；不会覆盖服务器 .env、nginx.conf、config/ 或改变业务卷名。

环境变量 RELEASE_IMAGE 由发布程序注入；API 生产 Compose 要求显式指定已拉取的 GHCR 镜像，不包含 build，也不使用本地镜像默认值。手工首次初始化须先在当前终端 export RELEASE_IMAGE 并成功 docker pull。不要在服务器 .env 固定 RELEASE_IMAGE 或手工启动旧镜像覆盖已发布版本。改 .env/nginx.conf 后通过 Actions 的发布流程重新创建应用容器。基础设施配置变更需独立审查和维护，自动发布不会重建数据库/Redis。

## 手工数据库备份参考

手工维护数据库前，先完成数据库备份。若使用上面的新基础设施，可在 API 目录执行（不适用于旧 ERP 数据库）：

```bash
mkdir -p /home/admin_chen/www/backups
umask 077
saveb_backup="/home/admin_chen/www/backups/api-$(date +%Y%m%dT%H%M%S)-$$.dump"
docker compose -f docker-compose.infra.yml exec -T postgres sh -c \
  'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$saveb_backup"
```

确认上一条成功且文件非空，再检查归档可读：

```bash
test -s "$saveb_backup" && docker compose -f docker-compose.infra.yml exec -T postgres \
  pg_restore --list < "$saveb_backup" > /dev/null
```

备份失败或归档检查失败时停止后续修改。pg_restore --list 只是归档可读检查，不能替代临时库真实恢复验证；附件和异机备份单独维护。自动发布中的 automation/backup.sh 同样先备份及检查归档，再执行增量迁移。

## 访问和验收

服务器上执行：

```bash
curl -f http://127.0.0.1:18088/up
curl -f http://127.0.0.1:18085/ready
curl -f http://127.0.0.1:13000/healthz
```

暂无域名时在 Windows PowerShell 使用现有 SSH 认证建立验收隧道：

```powershell
ssh -N -o ExitOnForwardFailure=yes -L 13000:127.0.0.1:13000 admin_chen@192.168.11.84
```

核对主机指纹并按提示认证，保持窗口打开，浏览器访问 http://127.0.0.1:13000/dashboard/overview 。测试阶段 API 的 APP_URL、FRONTEND_URL、CORS_ALLOWED_ORIGINS 可使用 http://127.0.0.1:13000，正式入口启用后改为实际 URL。隧道不需要修改 authorized_keys；直接访问服务器内网 IP 的 13000 端口在当前回环绑定下不可用。

正式多人访问的反向代理/域名另行配置，不修改宿主机现有站点。所有本项目服务器文件放在 /home/admin_chen/www，Docker 按正常机制写 /var/lib/docker；不操作旧 ERP、禅道的容器、数据卷或全局清理命令。

## Docker Hub 超时：切换基础设施镜像来源

`docker-compose.infra.yml` 支持在 API 根目录 `.env` 中设置镜像地址；默认仍是 Docker Hub。服务器无法连接 Docker Hub 时，可以将这两个字段改成 Docker 官方发布在 AWS ECR Public 的镜像：

```dotenv
INFRA_POSTGRES_IMAGE=public.ecr.aws/docker/library/postgres:16-alpine
INFRA_REDIS_IMAGE=public.ecr.aws/docker/library/redis:7-alpine
```

这两个参数只影响生产 PostgreSQL 和两个 Redis 服务。本地开发 Compose、GitHub 云端构建、应用 GHCR 镜像来源不变。不修改 Docker 全局镜像源、不重启 Docker，不改变数据库卷或网络名称。ECR Public 是另一条下载路径，仍需确认服务器网络能够访问。

首次启动基础设施时，在服务器执行以下命令；上一条成功后再执行下一条：

```bash
cd /home/admin_chen/www/saveb-api
git pull --ff-only
# 编辑现有 .env 的上述两个字段，不要覆盖其他凭据。
nano .env
docker compose -f docker-compose.infra.yml config --quiet
docker compose -f docker-compose.infra.yml config --images
docker compose -f docker-compose.infra.yml pull
docker compose -f docker-compose.infra.yml up -d --wait --pull never
docker compose -f docker-compose.infra.yml ps
```

如果 ECR Public 也超时，可继续使用已准备的离线镜像包：校验 SHA256 后 `docker load`，将上述两个参数分别设回 `postgres:16-alpine`、`redis:7-alpine`，再用 `up -d --wait --pull never` 启动。不要通过清理数据卷解决网络问题。已有运行中的基础设施再次执行 `up` 时，镜像改变可能重建对应容器，应安排维护时间。

官方来源：[Docker Official Images on ECR Public](https://aws.amazon.com/blogs/containers/docker-official-images-now-available-on-amazon-elastic-container-registry-public/)。

## 本地 Navicat 连接服务器 PostgreSQL

生产 PostgreSQL 支持映射到服务器内网地址。服务器 saveb-api/.env 设置：

```dotenv
POSTGRES_BIND_IP=192.168.11.84
POSTGRES_HOST_PORT=5433
```

模板默认绑定 127.0.0.1，服务器需要按上面改成内网地址；仅增加 .env 字段而不更新 Compose 不会生效。应用内部 DB_HOST=saveb-api-postgres、DB_PORT=5432 保持原值，Collector DSN 也继续使用容器内 5432。

提交代码后在服务器更新，并检查 5433 没有被其他服务占用：

```bash
cd /home/admin_chen/www/saveb-api
git pull --ff-only origin main
ss -lnt 'sport = :5433'
nano .env
docker compose -f docker-compose.infra.yml config --quiet
docker compose -f docker-compose.infra.yml up -d --no-deps --pull never --wait postgres
docker compose -f docker-compose.infra.yml ps postgres
```

新增映射会重建本项目 PostgreSQL 容器，数据库连接会短暂中断；继续挂载原 saveb-production-postgres 数据卷，不删除数据，不重建 Redis 或旧项目容器。如果 5433 已被其他服务占用，换一个空闲的 POSTGRES_HOST_PORT，不停止对方服务。

Navicat 新建 PostgreSQL 连接：主机 192.168.11.84，端口 5433，初始数据库使用 API .env 的 DB_DATABASE，用户名使用 DB_USERNAME，密码使用 DB_PASSWORD。数据库密码与后台 super_admin 的登录密码是两回事。Docker 服务名只供容器访问，不填进本机 Navicat。

本机 PowerShell 可先执行 `Test-NetConnection 192.168.11.84 -Port 5433`。如果失败，先核对端口映射和网络/防火墙规则，再处理认证；不需要修改 nginx.conf。本次配置不调整服务器全局 Docker 或防火墙配置。
