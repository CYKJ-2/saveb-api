# SAVEB API

部署复盘和日常命令统一入口：[部署与数据迁移操作手册](部署与数据迁移操作手册.md)。包含上周已完成的迁移记录、首次安装、三个项目更新、配置生效、备份与回退；已经上线后直接使用日常更新章节。

旧服务器业务数据迁移（保留新 RBAC）：见 [快照迁移与只读预检](LEGACY-BUSINESS-IMPORT.md)。旧系统继续使用时，先核对字段和约束，再用一致性快照在临时库演练；不要直接覆盖整库。

当前服务器部署采用 **本地 push → 服务器 git pull → 服务器构建镜像并启动 Docker Compose**，无需 GHCR 或 runner。完整命令见 [服务器启动说明](APPLICATION-START.md)；自动发布文档留作后续启用时参考。

**日常只维护根目录 `.env` 和 `nginx.conf`。** 本地与生产共用这两个配置入口；旧 `build/` 和环境切换符号链接已移除。首次配置、生产参数及配置生效方式见 [配置说明](CONFIGURATION.md)，服务器自动发布见 [AUTODEPLOY.md](AUTODEPLOY.md)。

后端为 PHP 8.3 / Laravel，数据库为 PostgreSQL 16，缓存为 Redis 7，由 Nginx 提供 HTTP 服务。全新 clone 请先阅读 [首次安装](RBAC-BOOTSTRAP.md)，无需开发者的本地数据库。

## 日常启动

在项目目录运行 `start.bat`，或 PowerShell：

```powershell
.\scripts\start-local.ps1
```

脚本会检查 Docker Desktop、启动项目服务；保留现有 `.env`、APP_KEY、数据库和附件。首次缺少应用镜像时自动构建。`vendor` 使用宿主机现有依赖，包含 PHPUnit。

| 服务 | 本地地址 |
| --- | --- |
| API | http://localhost:8080 |
| 健康检查 | http://localhost:8080/up |
| 接口文档预览 | http://localhost:8080/api-docs/index.html |
| PostgreSQL | 127.0.0.1:5433 |
| Redis | 127.0.0.1:6380 |
| 本次恢复的 saveb-admin | http://127.0.0.1:3001/dashboard/overview |

数据库名、用户和密码由安装者在自己的 `.env` 配置。数据库、Redis、附件分别保存在项目命名卷中，不依赖旧项目网络、卷或开发者已有数据。

## 接口说明文档

打开 [接口文档预览](http://localhost:8080/api-docs/index.html)，或直接用浏览器打开 [离线 HTML](public/api-docs/index.html)。文档包含实际路由、认证权限、路径/查询/请求体参数、字段校验、嵌套返回结构、虚构调用示例、错误码与 CSV 表头；支持搜索、方法筛选、明暗切换和 OpenAPI 下载。

接口变更后重新生成并检查：

```powershell
docker exec saveb-api-app php scripts/generate-api-docs.php
docker exec saveb-api-app php scripts/check-api-docs.php
```

也可使用 `composer docs:generate` 与 `composer docs:check`。查看 [维护与调用说明](docs/API-DOCUMENTATION.md) 和 [OpenAPI JSON](public/api-docs/openapi.json)。

## 全新空库初始化

首次新建环境无需配置管理员密码，默认账号密码为 `super_admin` / `123456`。全新 clone 的开发机先按 [完整安装步骤](RBAC-BOOTSTRAP.md) 安装 Composer 依赖和生成 APP_KEY，再初始化。现有本地启动脚本仍可使用：

```powershell
.\scripts\start-local.ps1 -InitializeDatabase
```

等价的数据库命令：

```powershell
docker exec saveb-api-app php artisan local:database-init
```

`local:database-init` 是仅允许 APP_ENV=local 的兼容入口，内部调用通用 `server:database-init --force`。通用命令支持本地和服务器，仅允许空 PostgreSQL schema；已有表时拒绝初始化，不自动清库。初始化流程：

1. 导入 `database/schema/newsql-baseline.sql` 中来自 `newsql.md` 的 45 张表。
2. 标记已由基线覆盖的 5 个历史结构迁移，避免重跑旧版删表重建逻辑。
3. 执行后续增量迁移，补齐当前业务功能字段和 2 张扩展表。
4. 运行 `RbacSeeder`，建立菜单、操作权限、内置角色和初始账号。

空库初始化完成后为 47 张业务/RBAC 表，加 1 张 Laravel `migrations` 表。预置 5 条在线表格目录；不生成虚构订单、销售额或库存。

文档 SQL 的落地修正：

- 补齐缺失的 bigint 主键序列与 `users.entity_uuid` 默认值。
- 所有表创建完成后再添加外键，解决 users/roles 以及其他跨表依赖顺序。
- 把采购任务的条件唯一约束转换为 PostgreSQL 支持的唯一索引。
- 当前页面新增 `business_operation_logs`、`online_spreadsheets`，以及附件归属、PayPal/仓库并发版本字段。
- 附件 SHA256 保留校验与索引，移除全局唯一约束，使相同图片的不同上传记录保持独立归属和 Invoice 绑定。

基线可以通过 `python scripts/generate-local-schema.py` 从文档重新生成。已有数据库应通过增量迁移调整，不能重新导入基线，也不要回滚其历史结构迁移。

## 权限和登录

`database/seeders/RbacSeeder.php` 是当前权限目录，共 18 个菜单、81 个操作权限，全部提供英文 `name` 和中文 `name_zh`。

- 首页：概览，各统计模块分别授权；订单管理在业务管理下。
- 业务：订单管理、Invoice、SA 销售、采购、仓库、达人、PayPal、工作巡查、Analysis。
- 系统：用户、角色、权限、采集管理；另有验货系统外链。

初始账号为 `super_admin`，默认密码为 `123456`，无需在 `.env` 中配置管理员密码。首次登录要求改密；账号已存在时，种子不修改其密码、状态或角色。

```powershell
docker exec saveb-api-app php artisan db:seed --class=RbacSeeder
```

重跑按权限 code 更新双语名称、归属和组件，保留 ID、启用状态及普通角色授权。超级管理员补齐所有有效权限；viewer 仅在新建时获得首页只读权限。普通 admin 角色需要在页面中按需授权。旧 `/orders/list` 菜单停用，其订单操作权限迁到业务管理下的订单管理。

## 镜像构建与更新

```powershell
.\scripts\start-local.ps1 -Build
# 网络需要代理时，参数只用于本次构建，不修改 Docker Desktop 设置：
.\scripts\start-local.ps1 -Build -BuildProxy http://host.docker.internal:7890
```

本地和生产统一使用 `Dockerfile`：本地选择 `development` 阶段，代码挂载、OPcache 实时检查修改；生产选择默认的 `production` 阶段，安装非 dev 依赖。Redis PHP 扩展固定为 [PECL redis 6.3.0](https://pecl.php.net/package/redis/6.3.0)。

已有基线只运行增量迁移：

```powershell
docker exec saveb-api-app php artisan migrate --force
docker exec saveb-api-app php artisan db:seed --class=RbacSeeder
```

停止服务使用相同 Compose 文件，不删除数据卷：

```powershell
docker compose -f docker-compose.yml down
```

## 验证与代码风格

```powershell
docker exec saveb-api-app php vendor/bin/phpunit -c phpunit-rbac.xml
docker exec saveb-api-app php vendor/bin/phpunit -c phpunit-orders.xml
docker exec saveb-api-app php vendor/bin/phpunit -c phpunit-dashboard.xml
docker exec saveb-api-app php vendor/bin/phpunit -c phpunit-workbench.xml
docker exec saveb-api-app php vendor/bin/phpunit -c phpunit-local.xml
```

测试仅使用随机 `rbac_test_*` schema，并在结束后清理。初始化专项测试覆盖字段、外键、权限路由、重复初始化保护、种子幂等和复合主键更新/删除。

四层代码按 novel-api 的可读性约定整理：中文职责/方法注释、模型字段说明、多行数组和查询链。现有 84 个文件通过语法树等价检查；另补齐文档新增表的 21 个 Model。

格式检查限定四层目录：

```powershell
docker exec saveb-api-app php vendor/bin/pint --config=pint.json --test app/Controllers app/Services app/Dao app/Models
```

宿主机已有 Composer 时可使用 `composer format` / `composer format:check`。不要省略目录直接对整个项目执行 Pint。

## 恢复范围

2026-09-07 已按用户要求从线上 phase4 库导入全部非 RBAC 表，共 45 张表、23,183 条记录，本地账号和授权保留；详见 [数据导入记录](DATABASE-IMPORT-20260907.md)。已恢复 961 个内嵌附件文件，另有 2,342 个文件需要原服务器附件存储。OCR 是原 ERP 的独立服务，仍需配置 `BUSINESS_OCR_URL` 并接入相同附件卷后才能使用。

当前配置说明见 [CONFIGURATION.md](CONFIGURATION.md)，生产应用只使用 `docker-compose.server.yml`，不与根目录本地 Compose 合并。
# GitHub 源码与本地文件

服务器自动发布见 [AUTODEPLOY.md](AUTODEPLOY.md)：本地 push main → GitHub 云端测试/构建 → GHCR → 内网 runner 拉镜像部署和健康检查。首次数据迁移及固定端口见 [SERVER-DEPLOY.md](SERVER-DEPLOY.md)。真实 .env、nginx.conf 和业务数据由服务器独立维护。

上传源码时保留根目录 `nginx.conf`、`.env.example`、`docker-compose.yml`、`docker-compose.server.yml`、`docker-compose.infra.yml`、`Dockerfile`、`docker/`、`automation/`、`.github/workflows/release.yml`、迁移、测试、文档及 `composer.lock`。真实 `.env`、`.config-backup/`、`vendor/`、`.erp-sync/`、运行日志和业务附件由 Git/Docker 忽略。唯一环境模板是根目录 `.env.example`；已有 `.env` 不要覆盖。

`scripts/test_auth.php` 和 `scripts/test_access_log.php` 是手动诊断工具，运行时须通过进程环境提供 `SAVEB_TEST_USERNAME`、`SAVEB_TEST_PASSWORD`；源码不内置登录凭据。

## 全新开发机与服务器安装

任何人 git clone 后，只需配置自己的 `.env`，创建 Docker 容器，再执行 `php artisan server:database-init --force`，即可创建全部结构、3 个基础角色、99 项菜单/操作权限及初始管理员。基础数据在 RbacSeeder 源码中；无需本地数据库快照、开发者账号或私有 JSON。首次管理员账号密码为 `super_admin` / `123456`，登录后修改密码。完整步骤见 [RBAC-BOOTSTRAP.md](RBAC-BOOTSTRAP.md)。

数据库初始化成功后的 API、Admin、Collector 启动与浏览器访问见 [APPLICATION-START.md](APPLICATION-START.md)。
