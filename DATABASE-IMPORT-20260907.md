# 线上业务数据导入记录（2026-09-07）

已从 `192.168.11.84:5432 / phase4` 的一致性快照导入 Docker 的 `test` 数据库。线上连接仅执行只读导出，没有执行写入、迁移或清理。

## 导入结果

- 共 45 张非 RBAC 表、23,183 条记录，逐表记录数及原始字段内容校验值全部匹配导出快照。
- 包含 public 下 38 张业务表、2 张 Knex 历史迁移表，以及 `saveb_release_backup_s54_20260803` 下的 5 张历史备份表。
- 源库没有的本地 `business_operation_logs`、`online_spreadsheets` 保留原数据；在线表格仍为 5 条。
- 本地 `users`、`roles`、`permissions`、`role_permissions`、`user_roles`、`api_tokens`、`audit_logs` 未导入线上数据。导入事务内对这些表，以及本地迁移记录和上述本地功能表，进行了导入前后的完整内容校验。
- 本地菜单仍为 15 个、操作权限仍为 67 个；原有本地账号、密码、角色与授权保留。
- 23 个关联序列已校正，并检查不低于导入主键最大值和源库已分配序列值。

## 结构兼容

原始字段值全部保留，未去重、改写订单号、替换操作人或删除历史记录。源表缺失的本地时间字段使用已有创建/更新时间补齐，没有对应时间时使用导入时间；本地软删除及版本字段沿用默认值。

线上存在 1,951 组重复客户端订单号，源库对此字段只有普通索引。新增迁移 `2026_09_07_140000_allow_legacy_client_order_ids.php` 移除文档基线多出的唯一约束，恢复此前 ERP 同步迁移的兼容行为；主订单号 `order_id` 和主键的唯一约束仍然有效。

由于明确排除了线上用户，以下历史用户引用原样保留，对应的本地外键标记为 `NOT VALID`：

| 表 | 历史引用数量 | 字段 |
| --- | ---: | --- |
| background_jobs | 707 | created_by_user_uuid |
| workflow_events | 67 | actor_user_uuid |
| order_user_overrides | 65 | updated_by_user_uuid |

这三项约束仍会检查后续新写入的引用，只是不要求导入的历史记录对应本地用户；其他业务外键均已验证。没有把线上操作人映射成本地管理员，也没有导入任何线上 RBAC 用户记录。

## 附件与验证

- 导入 3,303 条附件记录。
- 从业务 JSON 内嵌图片恢复 961 个文件，全部通过 SHA256 校验；通过前端代理读取已恢复截图返回 HTTP 200。
- 剩余 2,342 个文件不在本次可恢复的内嵌图片中，需要原服务器附件目录或存储备份。其数据库记录已完整导入。
- RBAC 回归：14 项测试、67 个断言通过。
- 真实登录及 35 个页面数据接口通过验证。
- 前后端服务已恢复运行，前端地址：http://127.0.0.1:3001/dashboard/overview 。

## 备份及校验材料

材料目录：`E:/wwwroot/db-import-20260907`。

- `local-before-import.dump`：开始导入工作前的完整本地备份。
- `local-pre-apply.dump`：正式导入前、包含兼容迁移的完整本地备份。
- `source-non-rbac.dump`：线上非 RBAC 数据一致性快照。
- `apply.sql` / `prepare.py`：经临时库验证的导入逻辑，明确列出导入表，无 CASCADE 清表。
- `test-verification.json`：逐表原始字段内容及记录数校验。
- `sequence-verification.json`：序列验证。
- `attachment-verification.json`：附件文件验证。

本次操作保留了备份文件。临时数据库及连接口令文件在验证后清理。今后恢复备份请先恢复到独立数据库验证，再决定目标库，不能直接重复执行清表导入 SQL。

## 逐表记录数

| 表 | 导入记录数 |
| --- | ---: |
| `public.attachments` | 3,303 |
| `public.background_jobs` | 707 |
| `public.daily_stats` | 2,796 |
| `public.exchange_rates` | 60 |
| `public.idempotency_keys` | 0 |
| `public.influencer_domains` | 239 |
| `public.influencer_order_links` | 0 |
| `public.influencers` | 0 |
| `public.invoice_adjustments` | 0 |
| `public.invoice_items` | 1,350 |
| `public.invoice_operation_logs` | 0 |
| `public.invoice_orders` | 943 |
| `public.invoice_staff_allocations` | 1,222 |
| `public.knex_migrations` | 12 |
| `public.knex_migrations_lock` | 1 |
| `public.legacy_dashboard_days` | 250 |
| `public.legacy_import_items` | 772 |
| `public.operation_cases` | 0 |
| `public.order_items` | 0 |
| `public.order_staff_allocations` | 74 |
| `public.order_staff_performance_projection` | 8 |
| `public.order_status_observations` | 5 |
| `public.order_user_overrides` | 65 |
| `public.orders` | 10,406 |
| `public.paypal_accounts` | 258 |
| `public.paypal_balance_entries` | 387 |
| `public.paypal_reviews` | 4 |
| `public.paypal_withdrawals` | 153 |
| `public.pending_completion_operations` | 5 |
| `public.procurement_removed_orders` | 0 |
| `public.procurement_tasks` | 0 |
| `public.purchase_task_items` | 0 |
| `public.purchase_tasks` | 0 |
| `public.shipment_tracking_events` | 0 |
| `public.site_classification_reclassifications` | 48 |
| `public.system_state` | 6 |
| `public.warehouse_receipts` | 0 |
| `public.warehouse_records` | 0 |
| `public.warehouse_shipments` | 0 |
| `public.workflow_events` | 67 |
| `saveb_release_backup_s54_20260803.daily_stats` | 40 |
| `saveb_release_backup_s54_20260803.legacy_dashboard_days` | 0 |
| `saveb_release_backup_s54_20260803.order_items` | 0 |
| `saveb_release_backup_s54_20260803.orders` | 0 |
| `saveb_release_backup_s54_20260803.system_state` | 2 |
