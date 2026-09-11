# 全新环境安装：源码、.env、Docker、数据库初始化

任何人 git clone 本仓库后，都可以仅凭仓库代码和自己填写的 `.env` 创建一个能登录的新系统。无需开发者的数据库、账号快照、备份或私有 JSON 文件。

## 初始化会做什么

`php artisan server:database-init --force` 同时适用于本地和服务器 PostgreSQL。它会在单个事务中安装仓库里的完整结构基线、登记已被覆盖的历史迁移、运行后续增量迁移，然后执行 `RbacSeeder`。失败回滚，目标 schema 非空时拒绝初始化。`--force` 不会绕过非空保护。

基础数据直接写在 `database/seeders/RbacSeeder.php`：当前包含 99 项菜单/操作权限，菜单名称、路径、层级、排序与当前基础配置一致，父子节点通过 code 关联，不固定本地数据库 ID。

- `super_admin`：超级管理员角色，具有全部有效权限。
- `admin`：管理员基础角色，具体授权在后台配置。
- `viewer`：初始只有首页查看权限，不能触发采集或执行写入操作。
- 初始账号为 `super_admin`，默认密码为 `123456`，无需在 `.env` 配置，首次登录后修改。

不会复制开发者的个人账号或其个别授权调整；默认密码仅用于首次创建 super_admin，不会重置已有账号。订单、Invoice 等业务数据为空；必要的系统结构和内置目录由迁移建立。API 和 Admin 可以先运行和登录，旧数据及附件之后按需导入。

普通 `php artisan migrate --force` 用于已有结构后的增量升级；它不会调用 Seeder。项目保留了历史结构迁移，所以全新库统一使用上面的初始化命令，无需另执行 migrate 或 db:seed。该命令只是封装仓库内的结构迁移和 Seeder，没有本地文件依赖。依赖和 .env 已就绪时，`composer setup` 也调用这一入口；不会再执行 Laravel 模板遗留的 npm 构建或创建 SQLite。

## 本地：从 git clone 到可用 API

以下为终端命令；Windows 可在 PowerShell 执行，`cp` 使用 PowerShell 的 Copy-Item 别名。

```bash
git clone git@github.com:CYKJ-2/saveb-api.git
cd saveb-api
cp .env.example .env
```

先编辑 `.env`：填写数据库密码、Redis 密码、共享服务 Token。本地使用 `APP_ENV=local`、`APP_DEBUG=true`、`API_PORT=8080`；`DB_HOST=saveb-api-postgres`、`REDIS_HOST=saveb-api-redis`。浏览器访问地址按自己的 Admin 地址填写，其他参数见 CONFIGURATION.md。

全新环境依次执行，上一条成功后继续：

```bash
docker compose build app
docker compose run --rm --no-deps app composer install --no-interaction --prefer-dist
docker compose run --rm --no-deps app php artisan key:generate
docker compose up -d
docker compose exec app php artisan server:database-init --force
```

`key:generate` 仅用于全新 `.env`，已有 APP_KEY 不要重置。安装 Composer 依赖是新开发机必需步骤，不依赖开发者已有的 vendor。生产镜像已内置 Composer 依赖，服务器不用运行 composer install。

本地 API 健康地址为 `http://localhost:8080/up`，接口文档为 `http://localhost:8080/api-docs/index.html`。启动 saveb-admin 并配置 API 地址后，用上面的初始账号密码登录。`local:database-init` 作为旧本地脚本兼容入口保留，内部调用相同初始化流程。

## 服务器：现有 GHCR + Compose 部署

基础设施已 healthy 时无需重建 PostgreSQL/Redis，也无需上传任何 RBAC 快照。先提交本次源码并等待 API Actions test/build 成功，再在服务器获取同一提交。保留已配置好的 `.env`，无需补填管理员密码。

```bash
cd /home/admin_chen/www/saveb-api
git pull --ff-only origin main
nano .env
```

默认管理员账号密码为 `super_admin` / `123456`，初始化时自动创建。

先确认 GitHub Actions 已成功构建服务器 HEAD 对应的镜像。在同一终端依次执行，拉取和镜像检查均成功后再启动临时迁移容器：

```bash
export RELEASE_IMAGE="ghcr.io/cykj-2/saveb-api:sha-$(git rev-parse HEAD)"
docker pull "$RELEASE_IMAGE"
docker image inspect "$RELEASE_IMAGE" --format '{{.Id}}'
docker compose -f docker-compose.server.yml --profile tools run --rm --no-deps --pull never \
  migrate php artisan server:database-init --force
```

镜像拉取需要 package 访问权限；如已有 GHCR 登录可直接使用。手工首次拉取私有镜像时，可以使用有权限账号的 classic PAT（read:packages），以隐藏输入登录到 www 下的临时 Docker 配置，避免修改服务器默认 Docker 登录配置：

```bash
export DOCKER_CONFIG="$(mktemp -d /home/admin_chen/www/saveb-api/.ghcr-auth-bootstrap-XXXXXX)"
read -r -s -p 'GHCR read:packages token: ' saveb_ghcr_token
printf '\n'
printf '%s' "$saveb_ghcr_token" | docker login ghcr.io -u CYKJ-2 --password-stdin
unset saveb_ghcr_token
docker pull "$RELEASE_IMAGE"
```

登录/拉取成功后再执行初始化命令。初始化不要求创建 `.deploy-ready`。后续正常 Actions 发布使用 job 的 GITHUB_TOKEN，不依赖这个 PAT。

初始化成功后，先运行 API（不启动 Collector）：

```bash
docker compose -f docker-compose.server.yml up -d --no-build --pull never --wait
curl -f http://127.0.0.1:18088/up
```

Admin 使用本仓库文档约定的独立 Admin 镜像与配置启动；也可按 AUTODEPLOY.md 为 API/Admin 启用 runner 发布。手工启动不生成 release 历史，第一次 Actions 成功发布后才有可回滚版本记录。Collector 等规则、来源账号及旧数据迁移计划确认后再启动，不能把其启动作为登录后台的前提。

如果本次设置了临时 DOCKER_CONFIG，完成拉取和运行后在同一终端执行 `docker logout ghcr.io`，再 `unset DOCKER_CONFIG RELEASE_IMAGE`。

## 后续升级和旧业务数据迁移

- 首次安装成功后，不再执行 server:database-init；后续用增量 migrate，或现有 Actions 发布流程。
- 需要更新基础权限目录时，更新 RbacSeeder 后显式执行 `php artisan db:seed --class=RbacSeeder --force`。它按 code 更新基础菜单配置、给超级管理员补新权限；保留已有账号密码/状态及普通角色授权。其他用户在后台创建。
- 旧业务迁移排除新系统的 RBAC 表及 migrations 表；订单、人工调整、规则和真实附件单独迁移。历史操作人 ID/UUID 要先制定映射，不能直接假设与新建管理员一致。
- API/Admin 可以先运行空业务库。导入业务数据时安排写入暂停和备份；Collector 在导入及规则配置完成后启用。
- 之前产生的私有快照仅是忽略目录中的历史备份，任何安装/迁移命令都不会读取它，也不需要传到服务器。

## 初始化时出现 PHP 基础镜像下载超时

如果看到 `[migrate internal] load metadata for docker.io/library/php`，说明服务器正在构建应用镜像，初始化命令尚未运行。旧版 server Compose 保留 build，并在未指定 RELEASE_IMAGE 时退回 saveb-api:server；镜像缺失时会触发本机构建。`docker compose run --pull never` 不能替代禁止构建，也不支持 `--no-build`。

当前生产 Compose 已移除 build，并要求明确设置 RELEASE_IMAGE；本地开发 Compose 仍支持构建。首次运行必须先由 Actions 构建并上传对应提交的 GHCR 镜像，在同一终端 export RELEASE_IMAGE、docker pull、docker image inspect 成功后再初始化。正常 runner 发布已经注入镜像 digest，无需更改工作流。

GHCR 如果报 denied/unauthorized，检查登录及 package 权限；如果报 manifest unknown，检查当前提交的 build 是否成功以及镜像标签。不要改用 PHP 基础镜像代替 API 成品镜像。
