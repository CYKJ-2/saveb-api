> 2026-09-07 更新：现已接入 saveb-collector 的真实任务和调度状态，新增 #refreshText 与手动当天采集按钮，详见 [接入说明](COLLECTOR-INTEGRATION.md)。下文“尚未接入采集器”的表述属于此前记录。

# 首页真实数据接入

日期：2026-09-06。页面：`saveb-admin/src/views/dashboard/index.vue`，地址 `/dashboard/overview`。

## 日期与统计口径

- 顶部显示北京时间和日期范围，进入页面默认查询北京时间当天；支持今天、昨天、近 7 天、本月及自选范围。
- 接口不传日期时也默认当天；只传一个日期时查询该日。范围最多 366 天，非法日期和倒置范围返回 422。
- Order 使用北京时间零点对应的 UTC 左闭右开区间；Invoice 使用业务日期 `coalesce(order_date, invoice_date)`。
- 核心指标统计已完成的非 Invoice 订单，排除测试订单、软删除记录；Invoice 在分类、趋势和最近订单中独立展示并去重。
- 环比对照紧邻的上一等长日期区间。上一期为零时增长率为 null，不伪造百分比。缺少汇率的订单计入订单/件数但不计入金额及客单价，并显示缺失提示。
- 线下客服订单数、件数及金额按原系统分摊比例统计。达人排行仅统计头部/中腰部达人的已完成订单。
- PayPal 展示所选期间收款、提现，收款按订单业务日期，提现按 `withdrawn_at`；历史停用账户的提现仍纳入统计。这是期间发生额，不是账户实时余额。
- 汇率取截至结束日期每种币种最近一条汇率，不读取未来汇率。数据覆盖状态和协作表格属于当前状态，页面明确标注不随查询日期变化。
- 当天没有数据时显示 0/空状态，不自动改查最近有数据的日期。

## 接口与权限

统一 `GET /api/dashboard/{module}`，可选参数 `startDate=YYYY-MM-DD&endDate=YYYY-MM-DD&granularity=day|month`。返回统一结构：

```json
{
  "code": 0,
  "data": {
    "range": { "startDate": "2026-09-06", "endDate": "2026-09-06" },
    "timezone": "Asia/Shanghai",
    "generatedAt": "2026-09-06T17:00:00+08:00",
    "data": {}
  }
}
```

| module | 内容 | 权限码 |
| --- | --- | --- |
| overview | 核心指标、付款状态、环比 | dashboard.overview.overview |
| sales-trend | 按日/月销售和订单趋势 | dashboard.overview.sales_trend |
| categories | 分类及销售占比 | dashboard.overview.categories |
| influencers | 达人销售排行 | dashboard.overview.influencers |
| staff | 线下客服业绩 | dashboard.overview.staff |
| recent-orders | 最近 10 条订单 | dashboard.overview.recent_orders |
| currencies | 原币和 USD 金额 | dashboard.overview.currencies |
| paypal | 期间收款、提现、账户排行 | dashboard.overview.paypal |
| exchange-rates | 截至结束日期的汇率 | dashboard.overview.exchange_rates |
| system-status | 库内业务日期范围、记录数、最近更新时间 | dashboard.overview.system_status |
| spreadsheets | 在线表格目录 | dashboard.overview.spreadsheets |

11 条路由均有 Bearer 认证和独立权限校验；仅有首页菜单不自动获得这些统计数据。新增权限挂在 `dashboard.overview` 菜单下，迁移 `2026_09_06_180000_add_dashboard_overview_permissions.php` 已执行，可重复运行，不改变普通角色原有授权。

## 文件结构

- `app/Controllers/DashboardOverviewController.php`：参数校验、日期默认值、响应。
- `app/Services/DashboardOverviewService.php`：分模块统计、环比、PayPal 期间金额；复用已校验的 OrderManagementService 和 OrderStatisticsService 统计口径。
- `app/Dao/DashboardOverviewDao.php`：汇率、账户、提现、数据覆盖状态、表格查询。
- `routes/dashboard.php`：模块路由及权限。
- 所有访问的表复用现有 Order、InvoiceOrder、InvoiceItem、InvoiceStaffAllocation、OrderUserOverride、OrderStaffAllocation、ExchangeRate、PaypalAccount、PaypalWithdrawal、OnlineSpreadsheet Model，无新增业务表。
- 前端 `src/api/dashboard.ts`：模块 API 及北京时间日期方法。
- 首页通过 `ModulePanel.vue`、`OverviewChart.vue`、`OverviewRanking.vue`、`OverviewCards.vue` 展示数据，沿用 Vben 主题变量和 Element Plus。各模块独立加载/报错/重试；切换日期清除旧结果，并阻止晚到的旧请求覆盖新数据。

首页已停止引用 `data/mockData.ts`。旧模拟组件保留在源码中但不再被该首页加载。原有假天气、固定采集成功/失败状态和虚构回填进度均不再显示。采集调度器、回填进度没有接入真实状态源，因此仅显示“尚未接入运行状态数据”，不提供虚假的刷新采集按钮。

修改前首页和 API 路由备份：`E:/wwwroot/overview-backup-20260906`。

## 验证

```powershell
docker exec saveb-api-app php vendor/bin/phpunit -c phpunit-dashboard.xml
docker exec saveb-api-app php vendor/bin/phpunit -c phpunit-orders.xml
docker exec saveb-api-app php vendor/bin/phpunit -c phpunit-rbac.xml
docker exec saveb-api-app php scripts/check_dashboard.php
# 在 saveb-admin 目录：
npm run build
```

首页测试 8 项/69 断言，订单回归 10 项/58 断言，RBAC 回归 14 项/67 断言，合计 32 项/194 断言通过。测试在随机 `rbac_test_*` schema 运行，整数 ID sequence 单独创建，未写实际业务表。

真实数据核对：2026-09-06 核心指标为 0；2026-08-01～2026-08-27 为 1,091 单、1,685 件、470,618.42 USD、客单价 431.36 USD。两个区间的全部 11 个模块均已读取验证。

浏览器验证了当天默认值、日期区间切换、真实历史图表、回到今天、模块空状态和界面运行错误。3000 与 3001 的 Vite 进程均使用同一个 saveb-admin 工程；3000 地址在本工具浏览器中需要重新登录，数据交互测试使用已有登录态的 3001 会话。构建通过，现有 Vite CJS 和大分包提醒未纳入本次改动。
