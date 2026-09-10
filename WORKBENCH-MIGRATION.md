# 其他业务页面迁移记录

更新日期：2026-09-06。

本机没有 `source-admin` 目录，本次沿用此前约定的 `saveb-admin`，接口放在 `saveb-api`。源实现为 `saveb-source/dashboard/index.html`、`invoice.html`、`sa-sales.html`、`online_spreadsheets.js`。页面内容使用 Vue 组件重写，保留源系统深色背景、渐变统计卡片、紧凑表格、筛选区和分区布局，沿用现有后台登录、菜单、标签页及 RBAC。

## 页面与文件

所有前端路径相对于 `E:/wwwroot/saveb-admin/src/views/workbench`。

| 原入口 | 新入口 | 页面文件 | 后端业务层 |
| --- | --- | --- | --- |
| invoice-add / invoice.html | /workbench/invoice | invoice/index.vue、InvoiceEditor.vue | InvoiceController / InvoiceService / InvoiceDao |
| sa-sales / sa-sales.html | /workbench/sa-sales | sa-sales/index.vue | SaSalesController / SaSalesService / SaSalesDao |
| purchasing-dept | /workbench/procurement | procurement/index.vue | ProcurementController / ProcurementService / ProcurementDao |
| warehouse-dept | /workbench/warehouse | warehouse/index.vue | WarehouseController / WarehouseService / WarehouseDao |
| creator-dept | /workbench/influencer | influencer/index.vue | InfluencerController / InfluencerService / InfluencerDao |
| paypal-balance-monitor | /workbench/paypal | paypal/index.vue | PaypalController / PaypalService / PaypalDao |
| operations-dept | /workbench/operations | operations/index.vue | OperationsController / OperationsService / OperationsDao |

原 `order-management` 继续使用已迁移的 `/dashboard/order-management`。原 `order-lifecycle`、`customer-service-dept`、`finance-dept` 是导航分组，没有独立业务面板，本次对应功能归入以上七个模块。

前端 API 封装为 `src/api/workbench.ts`；共用样式、权限方法、图表、鉴权图片分别位于 `shared/legacy.css`、`useWorkbench.ts`、`MetricChart.vue`、`AttachmentImage.vue`。

## 已实现的业务

- Invoice：分页查询、日期/关键字筛选、详情、创建、修改、软删除、商品及截图上传、截图 OCR、客服分摊、操作记录和日志 CSV。订单编号由服务端事务分配，分摊必须合计 100%，附件归属和修改版本由服务端验证；附件批量按 ID 加锁，避免重复绑定。
- SA：日期范围和快捷月份、销售/退款/净额、阶梯佣金、客服排名、渠道、支付方式、收款账户、每日趋势；Invoice 独立统计。CSV 包含总览、各维度汇总及订单明细。
- 采购：已完成订单来源池、手工补单、多商品明细、状态统计、供应商/成本/到仓时间/物流维护、移除、日志、CSV。Invoice 多商品保留为逐件采购明细；到仓自动交接仓库。
- 仓库：最近 120 天/历史/全部、状态统计、逐件质检、物流、部分发货、完整发货和处理历史。禁止超量发货、减少已发数量；发货需要质检通过及物流单号。同步采购状态，并使过期采购版本失效。交接后采购端不能变更商品和数量。
- 达人：网站归属目录、检索、分页、添加网站、域名规范化和归属冲突校验、月度销售排行及目录 CSV。缺失样品/佣金资料显示 `—`。
- PayPal：账户检索、余额汇总、页面阈值提醒、入账订单、提现记录、新增账户、余额修正、审核次数和 CSV；余额修正重设基准，操作带版本防止重复提交。
- 工作巡查：五个原 WPS 表格入口、名称/用途检索、部门筛选。

PayPal 页面维护内部台账，不发起资金转账。采购物流由记录维护，源系统未启用的承运商主动刷新没有伪造接通。在线协作表格、质检图片系统继续打开原外部系统。

OCR 使用已有本地 PaddleOCR 容器，返回文字及可识别的金额/日期建议，保存前人工核对；未移植旧 Node 的异步任务队列、历史 OCR 作业、完整 Invoice 证据仲裁引擎。不得把 OCR 建议当成已验证付款。

## 数据结构和权限

增量迁移：

- `2026_09_06_140000_add_remaining_business_modules.php`
- `2026_09_06_140001_add_workbench_permissions.php`

新增表：`procurement_tasks`、`procurement_removed_orders`、`warehouse_records`、`business_operation_logs`、`online_spreadsheets`。扩展 `attachments.owner_user_id`、`paypal_accounts.version`。所有使用的业务表均有对应 Model；旧 Invoice、Order Model 继续复用。

新增模型：ProcurementTask、ProcurementRemovedOrder、WarehouseRecord、BusinessOperationLog、OnlineSpreadsheet、Attachment、Influencer、InfluencerDomain、PaypalAccount、PaypalBalanceEntry、PaypalReview、PaypalWithdrawal。

两条迁移已在本地 Docker 数据库执行，保留既有数据和角色授权，重复执行权限种子不会重复插入。回滚方法保留业务数据，不能用 `migrate:fresh` 替代增量迁移。

七个菜单放在 `business` 目录下（当前中文侧栏显示“业务管理”），已写入现有 `permissions` 表：

| 权限前缀 | 操作后缀 |
| --- | --- |
| business.invoice | list, create, update, delete, ocr, logs, export |
| business.sa_sales | list, export |
| business.procurement | list, statistics, create, update, delete, logs, export |
| business.warehouse | list, update |
| business.influencer | list, statistics, create, export |
| business.paypal | list, orders, create, balance, review, withdrawal, export |
| business.operations | list |

合计七个菜单、30 个操作权限。超级管理员按既有规则可用；普通角色需在角色管理勾选相应菜单和操作。查看权限不会自动获得写入或导出权限，导出同时要求相应读取权限。进入 `/workbench` 自动选择首个有权访问的页面。

API 定义在 `routes/workbench.php`，统一前缀 `/api/workbench`，36 条路由全部经过 `auth.api` 和相应 `permission` 校验。附件的上传、读取、OCR 也执行独立权限及归属校验。

Controller 负责参数与响应，Service 负责业务和事务，Dao 负责查询/持久化，Model 对应表。审计使用当前 RBAC 用户整数 ID，旧 `invoice_operation_logs` 的 UUID 操作者结构保持原状。

## 本地 Docker 运行

新增本地覆盖文件 `docker-compose.modules.yml`，复用附件卷 `saveb-erp-dev_attachments` 和原 OCR 容器。已创建共享网络 `saveb-business-services`，只把新 API 和 OCR 服务接入此网络。

在 `E:/wwwroot/saveb-api` 执行：

```powershell
# 仅网络尚不存在时创建；OCR 容器重建后需重新接入。
docker network create saveb-business-services
docker network connect saveb-business-services saveb-erp-dev-ocr-engine-1
docker compose -f docker-compose.yml -f docker-compose.modules.yml up -d --no-deps app
docker exec saveb-api-nginx nginx -s reload
```

覆盖配置使用现有本机镜像 `saveb-api-app:local`；`BUSINESS_ATTACHMENTS_ROOT=/data/attachments`，`BUSINESS_OCR_URL=http://saveb-erp-dev-ocr-engine-1:8081`。

`docker/start-local.sh` 将 PHP 工作进程加入附件卷所在组，仅初始化新上传子目录 `api` 的权限。新上传文件保留私有组读权限；浏览器通过 Bearer 鉴权接口获取图片。PHP 上传限制 25MB、请求体 27MB；当前根目录 `nginx.conf` 统一设置 HTTP 请求上限 50MB，实际上传仍受 PHP 和业务校验限制。历史 Nginx 修改前备份位于 `E:/wwwroot/remaining-modules-backup-20260906/nginx.local.conf`。

本次仅重建了 API app 容器，未重建数据库、Redis 或旧 ERP 服务，也未发布到生产。

## 历史附件核对

业务数据库已有 2,792 条附件记录，最初本机附件卷仅有 11 条记录可读取。使用 `scripts/restore_workbench_attachments.php` 从已导入 raw 快照中恢复 960 条记录所需图片，均与记录 SHA-256 完全一致；共享同一文件的记录可能多于实际文件数。没有覆盖已有原图或修改业务记录。

恢复后：971 条可读，1,821 条缺失，校验失败 0；缺失项中有 1,236 条被现有 Invoice 或商品明细引用。已检查 `saveb-source/data/invoice_orders.json`、`saveb-sales-data/invoice_orders.json`，未找到剩余图片的匹配字节。完整恢复仍需要原附件卷/文件备份。当前页面对缺失文件显示“附件不可用”；编辑这些历史单据时需补传缺失附件。

恢复脚本默认只核对，`--apply` 才补写；只接受库内记录路径，并在补写前后校验 SHA-256。可提供额外本地 JSON 文件作为候选来源：

```powershell
docker exec saveb-api-app php scripts/restore_workbench_attachments.php
docker exec saveb-api-app php scripts/restore_workbench_attachments.php --apply
docker exec saveb-api-app php scripts/check_workbench.php
```

历史数据读取核对：Invoice 802 单、采购来源池 10,631 单、达人目录 83 组、PayPal 活跃账户 258 个、在线表格 5 个。旧系统采购/移除/仓库持久记录表为空，仓库显示空状态符合当前数据。

修复了共享订单查询的内存问题：统计不再加载订单 raw 内的整份图片，只读取统计所需的分摊和人工修正字段；编辑时仍加载完整记录，避免损坏快照。

## 验证

```powershell
docker exec saveb-api-app php vendor/bin/phpunit -c phpunit-workbench.xml
docker exec saveb-api-app php vendor/bin/phpunit -c phpunit-orders.xml
docker exec saveb-api-app php vendor/bin/phpunit -c phpunit-rbac.xml
# E:/wwwroot/saveb-admin
npm run build
```

测试使用随机 `rbac_test_*` 隔离 schema，复制空表结构后为 ID 建立独立 sequence，不写入实际业务表；测试图片使用单独临时目录。

浏览器已核对七个页面、真实列表、采购多商品编辑、工作巡查搜索、Invoice 截图/商品图片预览和本地 OCR 返回。保存、删除、提现、发货等写操作在隔离测试数据中验证。

前端构建通过；现有 Vite CJS 提示和大分包提醒仍存在。原订单回归 10 项/58 断言，RBAC 回归 14 项/67 断言，新业务回归 14 项/99 断言，合计 38 项/224 断言全部通过。工作台根入口跳转到有权访问的页面已验证，浏览器检查未发现运行错误。
