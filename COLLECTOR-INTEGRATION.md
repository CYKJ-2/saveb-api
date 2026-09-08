# saveb-collector 接入（2026-09-07）

## 2026-09-08 日期字段升级

先执行 `2026_09_08_120000_separate_order_source_times.php`，再执行 Collector 迁移（schema version 4）。`orders.order_time` 采用来源创建日期；`source_created_at`、`payment_time`、`completed_time`、`source_updated_at`、`legacy_accounting_time` 均可为空，因此旧服务器带列名的数据导入无需提供这些新字段。导入后的日期归一化、历史统计重建与人工调整保护见 [订单日期与旧数据迁移说明](../saveb-collector/订单日期与旧数据迁移说明.md)。

数据目标是 saveb-api 的 PostgreSQL。saveb-admin 通过 saveb-api 认证和权限接口触发采集；不直接访问 Python 服务，不需要 saveb-erp 中转或 saveb-sales-data 文件导入。

## 数据链路

`DH-Order /order/list2 → collector.chunks.raw → 标准化 → collector.source_orders + orders → daily_stats / legacy_dashboard_days / system_state`

源订单、业务订单、受影响日期统计和分片提交标记在同一事务中写入；失败回滚。业务表沿用 API 已有结构。新增订单使用数据库的 UUID、可见订单号默认值，更新递增 version；保留人工修改字段、独立的人工调整表以及软删除订单。小时汇率采集同时写入 API 的 exchange_rates（取倒数转成 rate_to_usd）。

## 首页

- 组件：`saveb-admin/src/views/dashboard/components/CollectorStatus.vue`，首页加载，DOM ID 为 `refreshText`。
- 正常、排队/采集中、失败、超过 30 分钟未成功入库、调度心跳缺失均有对应提示。排队/运行超过 35 分钟报警。
- `collector.jobs` 的已发布实时任务决定最近成功入库时间。历史任务、dry-run、影子采集和其他账户不计入健康状态。
- `collector.scheduler_state` 单独记录定时调度心跳；手动成功不会掩盖 Beat 故障。
- 正常每 10 秒轮询，连接错误每 30 秒重试，卸载清理定时器；发现新的成功任务时刷新首页当前所选日期的数据。
- “采集当天数据”只提交北京时间当天一个分片，不追加历史巡检与历史未完成订单。原日期筛选不影响按钮日期。

## 接口和权限

| 接口 | 权限 |
| --- | --- |
| `GET /api/collector/status` | `dashboard.overview.collector_status` |
| `POST /api/collector/today`，正文 `{"requestId":"客户端生成的 UUID"}` | `dashboard.overview.collector_trigger` |

均要求 auth.api Bearer。两个权限独立，不从首页菜单继承；超级管理员沿用原有 RBAC 机制。普通用户需要在角色权限页明确授权。POST 返回 202 代表已受理，不代表成功。浏览器超时重试复用 requestId；API 从 auth_user 设置 actor，固定 mode=today，并核对返回任务确实存在当前 API 数据库且为发布模式，防止错库或影子采集误报。

## 本机配置和启动

已把采集器数据库地址配置为当前 API 数据库，并生成、同步服务令牌到两边受忽略的 .env。未输出令牌。已应用独立 collector schema 和两个权限的增量迁移，未改写现有订单。

**已接入账号密码登录并完成本地实采。** 来源账号和密码存放于受忽略的 saveb-collector/.env；也兼容手动 Cookie。Docker 构建遇到本机 Clash DNS 问题时，使用 collector 的 scripts/start_local.ps1 -OfflineBuild。标准启动命令：

```powershell
cd E:/wwwroot/saveb-collector
docker compose build
docker compose run --rm migrate
docker compose run --rm api python scripts/preflight.py
docker compose up -d
```

API 与 Collector 共用现有 `saveb-api_saveb-api-net` 网络，Collector API 别名为 `saveb-collector-api`。通过 SAVEB_API_NETWORK 可更换网络。Collector Redis 仍使用专属网络和数据卷。只启动一个 Beat。

重新配置其他本地 API 实例时，可执行 `python scripts/configure_api.py --api-dir ../saveb-api`。该脚本用于此 Docker 布局，读取 API .env 的数据库凭据，保留已有 Cookie，配置 SAVEB_PUBLISH_API=true；其他部署布局直接配置 DSN/网络/服务地址。生产密钥由部署环境注入。API 环境变量为 SAVEB_COLLECTOR_URL、SAVEB_COLLECTOR_TOKEN、SAVEB_COLLECTOR_ACCOUNT；采集器对应 SAVEB_API_TOKEN、SAVEB_SOURCE_ACCOUNT。修改配置后运行 API 的 `php artisan config:clear`，并重启采集器。

不再使用 SAVEB_PUBLISH_ERP。API 写入开关为 SAVEB_PUBLISH_API；关闭后为影子模式，首页不会把影子任务显示为采集成功。旧任务冻结旧上下文，切换配置后请创建新任务，不要恢复旧 ERP 任务。

## 验证

```powershell
docker exec saveb-api-app php vendor/bin/phpunit -c phpunit-collector.xml
docker exec saveb-api-app php vendor/bin/phpunit -c phpunit-dashboard.xml
docker exec saveb-api-app php vendor/bin/phpunit -c phpunit-rbac.xml
cd E:/wwwroot/saveb-admin
npm run build
```

PHP 测试仅写随机 rbac_test_* schema；Collector 使用独立空测试库，复制当前 API 表结构验证。已通过真实账号登录、实际当天采集和当前 API 数据库入库验证。

本次验证结果：Collector 共 35 项测试通过（含真实 Redis/Celery → 模拟 DH HTTP → 复制 API 表结构的 PostgreSQL 入库；新增用例单独回归）。PHP 28 项测试、158 项断言通过，管理端 Vite 构建通过，Ruff/compileall/Compose 配置检查通过。浏览器模拟接口验证了排队禁用、成功恢复与自动刷新事件；真实 API 数据库规则上下文通过只读检查。临时测试容器和页面已清理。


## 账号登录与本地实采更新

2026-09-07 已上线本地 Docker 服务：API、collect/history worker、maintenance、Beat、专属 Redis。账号密码登录在采集器内完成，验证码本地 CPU 处理，会话缓存在专属 Redis，失效时自动重登。未沿用桌面旧脚本的 systemd 凭据目录、Tesseract 或固定服务器路径。

首次当天任务 `f1e881ae-f510-491d-ad22-1dbcdd2aa78e` 已成功提交：抓取 196 条补边窗口记录，筛选今天 84 条并写入当前 API 数据库；40 项测试通过（新增协议用例单独回归），可选独立 Redis 用例本次未运行；真实部署已完成 Redis/Celery 到业务数据库的端到端采集。API 到 Collector 的 HTTP 检查为 200，首页状态接口能够读取成功时间和调度心跳。

补充协议验证：网站订单页面实际使用 JSON POST；零宽度日期的空结果返回 code=1/msg=Success/data=null/totalElement=null。采集器只兼容这个字段齐全的明确格式，缺字段或错误代码仍然失败。历史已知订单按 ID 查询也已成功返回并完成标准化验证。

运行 Redis 使用唯一别名 saveb-collector-redis，避免与 API 网络内同名 redis（需要密码）的服务混淆。普通角色仍须分别授权“查看采集运行状态”“手动采集当天数据”。

只读部署检查：`docker exec saveb-api-app php scripts/check_collector.php`。


## 2026-09-07 备注版本冲突修复

首页 SOURCE_VERSION_CONFLICT 的原因：失败分片 4 和 6 与已入库原始记录对比，分别有 1、2 条订单仅 order.remark 变化，上游 updateTime 未变化，标准化业务列没有变化。原规则比较全部来源字段，因此错误地终止整个分片。

已对 `order.remark` 添加精确例外：备注继续保存在归档/来源原始记录，但不作为订单版本冲突依据。金额、支付状态及其余来源字段仍受保护；不跳过冲突订单，也不清空现有数据。14 项事务/恢复回归通过，其中新增备注更新、人工字段保留，以及备注与金额/状态同时变更仍回滚的验证。

手动任务 f417d933-d3f3-4701-a034-88d96dea7045 重试后于北京时间 17:12:14 成功：85 条当天订单，新增来源记录 1 条、更新 6 条、未变化 78 条。已部署到本机采集容器；失败分片恢复沿用原任务 actor，保留成功分片。

## 动态采集管理（2026-09-07）

新增 `/system/collector` 页面，完整说明见 [Collector 项目说明第 11 节](../saveb-collector/项目说明文档.md)。原固定 30 分钟计划已改为数据库配置，允许 5–1440 分钟，默认 30。Beat 每分钟调用 `app/queue/tasks.py::check_schedule`，间隔保存在 `collector.schedules`；每分钟检查的心跳独立于实际采集成功，启用动态配置后 5 分钟未检查提示异常。

后端采用 CollectorManagementController → CollectorManagementService → CollectorManagementDao → CollectorSchedule / CollectorJob / CollectorChunk。新增 settings、jobs、job detail、reprocess 路由，继续使用 auth.api 和独立权限；前端不会获得内部服务令牌或原始客户归档。

页面权限为 `dashboard.collector`，操作权限分别为 `.settings`、`.collect`、`.reprocess`。普通角色需明确授权；新权限迁移不会自动授权所有角色。接口允许 history/missing 范围采集，reprocess 强制预览。任务列表包含预览/历史，首页成功时间仍只来自实际发布的 refresh/today。

升级命令（先在 saveb-collector 目录完成镜像构建）：

```powershell
docker compose run --rm migrate
docker compose up -d --no-build api worker history maintenance beat
docker exec saveb-api-app php artisan migrate --path=database/migrations/2026_09_07_235000_add_collector_management_permissions.php --force
```

保存间隔从当前时间重新计时，不重启也不取消已有任务；自动任务未完成时不重复堆积新的自动任务。任务详情显示日期分片、计数和安全错误码，归档重算先验证所选日期存在原始归档。
