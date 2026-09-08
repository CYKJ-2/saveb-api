# 首页订单管理迁移

前端入口：`/dashboard/order-management`，菜单：**首页 → 订单管理**。

参考 `saveb-source/dashboard/index.html` 的 order-management 内容，以及
`saveb-erp/api/src-ts/services/legacyDashboard.ts`、`statsRules.ts`、`compat/dashboard.ts` 的统计规则。
旧地址在内置浏览器被阻止打开，因此按本地源码核对；新页面已在浏览器验证。

## 接口与分层

所有接口前缀 `/api/order-management`，均要求 Bearer 登录。

| 接口 | 权限（前缀 system.order.） | 用途 |
| --- | --- | --- |
| GET /statistics/overview | statistics.overview | 成交订单、件数、美元销售额 |
| GET /statistics/currencies | statistics.currencies | 原币及折算美元汇总 |
| GET /statistics/sales-trend | statistics.sales-trend | 日/月分类堆叠趋势 |
| GET /statistics/categories | statistics.categories | 分类订单、件数、金额及占比 |
| GET /statistics/influencers | statistics.influencers | 达人销售排行 |
| GET /statistics/staff | statistics.staff | 线下客服分摊 |
| GET /orders | list；测试记录额外 testing | 查询、分页、最近订单、下钻 |
| GET /export | list + export；测试记录额外 testing | 导出全部筛选结果 CSV |
| PUT /orders/{id}/staff | list + update；测试记录额外 testing | 编辑分摊、待处理确认完成 |

- `OrderStatisticsController → OrderStatisticsService → OrderManagementService → OrderManagementDao → Model`。
- `OrderManagementController → OrderManagementService → OrderManagementDao → Model`。
- 统计和查询共用归一化服务及 Dao，避免同一订单在统计与下钻中出现不同口径。
- 涉及的表都有 Model：Order、InvoiceOrder、InvoiceItem、InvoiceStaffAllocation、ExchangeRate、OrderUserOverride、OrderStaffAllocation。
- 前端独立 API 文件 `src/api/order-management.ts`，页面及图表在 `src/views/dashboard/order-management/`；沿用项目 Vben 主题变量和 Element Plus 组件。

统计必填 `startDate/endDate`（YYYY-MM-DD），最多相差 366 天；趋势增加 `granularity=day|month`。
查询支持 `customerService/customerName/orderId/paypalOrderId/paypalAccount/website/orderStatus/classification/influencer`、日期区间、`page/per_page`（最大 100）、`scope=normal|testing`。
普通查询为不区分大小写的包含匹配；统计下钻以 `staffExact/influencerExact` 保证客服及达人名称精确匹配。

## 数据口径

- 业务日期采用 Asia/Shanghai，订单按半开区间 `[起日00:00, 末日次日00:00)` 查询，保留末秒微秒记录。
- Complete、Completed、Paid、Success 统一为 completed。测试客户名按旧代码的 `\btest` 排除。
- 顶部 KPI、币种面板不含 Invoice，遵循旧页面；分类、趋势及最近订单包含 Invoice，页面明确提示。
- Invoice 使用 order_date，缺失时使用 invoice_date；商品件数使用 invoice_items，客服来自 invoice_staff_allocations。
- 同订单号的 Invoice 以 invoice_orders 为准，排除 orders 中的重复来源；不会按客户名或金额猜测合并。
- 美元金额优先使用订单已存 amount_usd（包括零）；缺失时使用不晚于业务日期的最近汇率。无汇率不伪造金额，概览返回 missingRates。
- 多人客服按归一化 shareRatio / percent 分摊，未显式分配比例时均分。线下客服统计不包含 Invoice。
- 历史 order_user_overrides 和 order_staff_allocations 参与有效状态及分摊计算。
- 保存客服使用事务、行锁和必填 version，冲突返回 409；比例必须合计 100%，主客服不可替换。修改前后及操作者保存在 raw.dashboardEditHistory；本地修正优先于历史导入覆盖值。
- 此处“确认完成”只修改内部订单记录，不调用支付平台。Invoice 的录入/OCR等其他部门页面不属于本次入口迁移。
- CSV 防护公式前缀，沿用相同筛选及权限；不输出 raw 中的原始附件和历史快照。

## 权限与运行

已执行且仅执行新增权限迁移：

```sh
docker exec saveb-api-app php artisan migrate --path=database/migrations/2026_09_06_120000_add_order_management_permissions.php --force
```

新增 `dashboard.order_management` 菜单及 12 个 action；未改变普通角色已有授权。管理员可在角色授权中分别选择统计、查询、导出、编辑及测试记录权限。旧 CRUD 的 create/update/delete/list 权限也补齐到此菜单下。

这次未运行业务表重建迁移、未重新导入旧数据、未修改现有业务订单。现有 orders 最新业务时间为 2026-08-27；默认本月没有订单时可切换日期或全量查询。

## 验证

```sh
docker exec saveb-api-app php vendor/bin/phpunit -c phpunit-orders.xml
docker exec saveb-api-app php vendor/bin/phpunit -c phpunit-rbac.xml
docker exec saveb-api-app php scripts/audit_rbac.php
cd saveb-admin && npm run build
```

订单测试使用随机隔离 PostgreSQL schema，覆盖：时间边界、测试记录、软删除、状态别名、Invoice 去重、汇率、零金额、分摊及下钻、独立权限、保存版本冲突、CSV 安全、历史覆盖、权限迁移幂等。
本次结果：订单 10 项 / 58 断言通过，RBAC 14 项 / 67 断言通过。权限审计没有缺失接口权限及无效父节点。浏览器验证 8 月线下分类 598 单，下钻结果 598 单；修复了查询布尔参数序列化及历史多人客服主客服解析问题。
读库诊断：`scripts/check_order_dashboard.php`；结构摘要：`scripts/inspect_orders.php`。

当前实现按日期读取并在服务层统一多来源口径；跨年大批量查询/导出仍需要读取对应记录。后续数据量显著增长时可迁移为数据库投影聚合，保持当前接口契约。
