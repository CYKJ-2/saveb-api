# 分页与导出统一（2026-09-08）

## 页面与接口

前端分页统一使用 `saveb-admin/src/components/common/ApiPagination.vue`，默认 20 条，可选 20 / 50 / 100 条，包含上一页、下一页、当前页、总页数、总记录数和页码跳转，支持中英文及明暗主题。更改每页条数回到第一页。

覆盖订单管理的状态列表及查询结果、Invoice 列表及日志、SA 订单明细、采购列表及来源选单和日志、仓库列表、达人排行和网站目录、PayPal 账号及收款/提款记录、用户/角色管理、采集任务及分段详情。旧 business/OrderList 菜单组件入口现在复用真实订单管理页面，避免使用原演示数据。

列表请求携带 `page` / `per_page`，接口仅返回该页明细及总数。Invoice、用户、角色、日志和采集数据使用数据库分页；合并来源或需业务计算的列表在 Service 完成筛选、去重和排序后分页响应。接口限制每页最多 100 条，默认 20 条。统计卡片和图表使用完整筛选范围；达人图表独立返回前 30 名。SA 报表接口不再随报表返回全部订单明细，明细使用独立分页接口，导出按完整数据生成。

导航树、权限树、表单选项及在线表格目录保留其结构；这些不是需要按页翻阅的明细表。没有更改 RBAC 授权规则或重置权限数据。

采购选单使用 `available_only=1`，在服务端先排除已有采购任务的订单，再统计数量和分页，避免前端过滤当前页造成空页。采购查询、翻页和导出使用已提交的筛选条件。

## 导出对照

所有下载由接口生成完整结果，不受当前页及每页条数限制。界面自动传递当前 `locale`（zh-CN / en-US），后端按对应语言生成表头，CSV 使用 UTF-8 BOM、CRLF 换行、正确转义，金额保留两位，继续防止文本单元格被解释为公式。

| 导出 | 原平台参考 | 结构 |
| --- | --- | --- |
| 订单查询 | saveb-erp/api/src-ts/routes/orders.ts：ordersToCsv | 订单ID、PayPal订单ID、客户、网站、状态、客服、收款PayPal、金额、币种、日期，共 10 列 |
| 采购订单 | saveb-source/dashboard/index.html：downloadPurchaseRows | 原 19 列，补齐 PayPal 订单ID、币种、采购员、预计到货、物流公司/电话、物流状态、优先级等 |
| PayPal 收款订单 | paypalReceivedOrdersCsv | 原 9 列，补回 PayPal 订单ID 和币种，金额使用原币金额 |
| PayPal 提款记录 | paypalWithdrawalRecordsCsv | 日期、账号名、邮箱、提款金额、来源；下载需提款查看和导出权限 |
| PayPal 修改日志 | paypalChangeLogsCsv | 原 9 列，保留修改前后值及修改人 |
| 达人网站映射 | saveb-erp/api/src-ts/routes/extensions.ts | 达人、网站、已确认，共 3 列 |
| Invoice 操作日志 | 同文件 invoice-operation-logs.csv | 原 8 列，含操作人、订单号、客户、变更字段和详情 |
| 采购操作日志 | saveb-erp/api/src-ts/routes/procurement.ts | 操作时间、操作、对象、记录ID，共 4 列，包含仓库操作 |
| SA 销售报表 | saveb-erp/api/src-ts/routes/saSales.ts：reportCsv | 恢复标题/日期与数据时间、汇总、员工排行、Invoice 员工排行、渠道统计、每日统计、订单明细的分段 CSV |

新项目已有的 PayPal 账号导出也支持对应语言，并包含账号创建日期。

## 验证

- Workbench 回归：45 项、526 次断言，1 项无关的本地 OCR 测试按环境条件跳过，其余通过。
- 补充采购选单和仓库分页：1 项、12 次断言，通过。
- 订单管理：14 项、111 次断言，通过。
- RBAC：14 项、67 次断言，通过。
- 采集分页与权限：3 项、28 次断言，通过。
- 修改的生产 PHP 文件通过 Pint；前端最终 `npm run build` 通过（19.01 秒）。
- 浏览器验证达人 20 条默认值、第二页、83 条数据末页 3 条、切换 50 条回到第一页、中英文和明暗主题；PayPal 20 条分页、263 个账号汇总跨页不变、提款记录分页；控制台无新增错误。

代码修改前备份与测试/构建日志位于 `E:/wwwroot/pagination-export-backup-20260908`。测试使用随机隔离 schema，未清空或重建业务数据库。
