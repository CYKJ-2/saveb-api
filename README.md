# SAVEB API 本地运行

Docker Desktop 重装后的环境已于 2026-09-07 恢复。后端为 PHP 8.3 / Laravel，数据库为 PostgreSQL 16，缓存为 Redis 7，由 Nginx 提供 HTTP 服务。

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
| PostgreSQL | 127.0.0.1:5433 |
| Redis | 127.0.0.1:6380 |
| 本次恢复的 saveb-admin | http://127.0.0.1:3001/dashboard/overview |

数据库名及用户沿用本地 `.env` 的 `test`，密码以该文件为准。数据库、Redis、附件分别保存在项目命名卷中；当前本地配置不依赖旧 saveb-erp 网络或卷。

## 全新空库初始化

仅首次新建环境使用：

```powershell
.\scripts\start-local.ps1 -InitializeDatabase
```

等价的数据库命令：

```powershell
docker exec saveb-api-app php artisan local:database-init
```

命令仅允许 `APP_ENV=local`、PostgreSQL、当前 schema 没有任何表的情况。已有表时拒绝初始化，不自动清库。初始化流程：

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

`database/seeders/RbacSeeder.php` 是当前权限目录，共 15 个菜单、67 个操作权限，全部提供英文 `name` 和中文 `name_zh`。

- 首页：概览、订单管理，各统计模块分别授权。
- 业务：Invoice、SA 销售、采购、仓库、达人、PayPal、工作巡查。
- 系统：用户、角色、权限管理，包含用户角色授权和角色权限分配。

本地初始账号：`super_admin` / `123456`，设置了首次改密标记。可以通过 `RBAC_SEED_ADMIN_PASSWORD` 指定首次创建密码；非本地环境必须显式设置。账号已存在时，种子不修改其密码、状态或角色。

```powershell
docker exec saveb-api-app php artisan db:seed --class=RbacSeeder
```

重跑按权限 code 更新双语名称、归属和组件，保留 ID、启用状态及普通角色授权。超级管理员补齐所有有效权限；viewer 仅在新建时获得首页只读权限。普通 admin 角色需要在页面中按需授权。旧 `/orders/list` 菜单停用，其订单操作权限迁到首页订单管理。

## 镜像构建与更新

```powershell
.\scripts\start-local.ps1 -Build
# 网络需要代理时，参数只用于本次构建，不修改 Docker Desktop 设置：
.\scripts\start-local.ps1 -Build -BuildProxy http://host.docker.internal:7890
```

本地使用 `build/Dockerfile.local`，代码挂载、OPcache 实时检查修改；生产配置仍使用 `build/Dockerfile`。Redis PHP 扩展固定为 [PECL redis 6.3.0](https://pecl.php.net/package/redis/6.3.0)。

已有基线只运行增量迁移：

```powershell
docker exec saveb-api-app php artisan migrate --force
docker exec saveb-api-app php artisan db:seed --class=RbacSeeder
```

停止服务使用相同 Compose 文件，不删除数据卷：

```powershell
docker compose -f docker-compose.yml -f docker-compose.local.yml down
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

生产部署说明保留在 `build/docker/README.md`。
# GitHub 源码与本地文件

生产自动发布请使用 [GitHub Actions + GHCR 自动发布与回滚](AUTODEPLOY.md)。新的入口为 `.github/workflows/release.yml` 和 `deploy/`；服务器准备完成并配置 `DEPLOY_ENABLED=true` 后，推送 main 自动部署。

上传源码时保留 `build/`（它是 Docker、Nginx 和启动脚本源码，不是编译产物）、迁移、测试、文档及 `composer.lock`。真实 `.env`、`build/env/*.env`、`vendor/`、`.erp-sync/`、运行日志和业务附件由 Git 忽略，保留在本地。环境模板和初始化说明见 [build/README.md](build/README.md)。

`scripts/test_auth.php` 和 `scripts/test_access_log.php` 是手动诊断工具，运行时须通过进程环境提供 `SAVEB_TEST_USERNAME`、`SAVEB_TEST_PASSWORD`；源码不内置登录凭据。
