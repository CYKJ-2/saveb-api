# 旧 ERP → 新 API：业务数据快照迁移

本次来源为 `saveb-erp-phase4-task2-a823095-20260718-190342-postgres-1` 的 `phase4` 库；目标为 `saveb-infra-postgres-1` 的 `saveb` 库。旧后台继续运行，本次仅导入导出快照中的已有数据，不是停机切换，也不是持续同步。快照开始后提交的变更不保证包含在本次导入中。

当前已完成历史导入方案复查和只读预检工具，**尚未在服务器导出或导入数据**。预检脚本没有写入模式，不能用它代替正式迁移。

## 上次本地导入实际如何处理差异

已复查 `E:/wwwroot/db-import-20260907/prepare.py`、`verify.py`、`verify_sequences.py` 和当时的两库元数据；结果记录在 [历史导入记录](DATABASE-IMPORT-20260907.md)。这些路径只是历史材料，本次服务器运行不依赖它们。

1. 先将来源一致性快照恢复到临时数据库，取得源表的明确字段清单。
2. 使用 `COPY table (column1, column2, ...)` 导入；同名字段按名字对应，不能使用 `INSERT SELECT *` 或按列位置搬运。
3. 当时共有字段没有类型差异，目标多出 76 个字段。新增的 `created_at` / `updated_at` 优先使用同一条记录原有时间，没有可用值时按目标默认值；可空字段和版本字段沿用目标定义。
4. 旧库 `client_order_id` 存在重复，目标多余的唯一约束需要兼容。当前 API 已有 `2026_09_07_140000_allow_legacy_client_order_ids.php` 迁移；主订单号 `order_id` 不改写、不去重。是否存在其他唯一索引仍以当前库为准。
5. 不导入旧 RBAC。历史用户 UUID 保留，业务表到目标 `users` 的引用需要逐项核查。上次有历史孤立引用的三张表是 `background_jobs`、`workflow_events`、`order_user_overrides`，对应外键重建为 `NOT VALID`；新写入仍受约束。不能把所有外键都设为无效，也不能自动把历史操作人改成新管理员。
6. 业务字段的行数和内容校验值逐表对比；受保护的 RBAC 和本地功能表导入前后校验。校正自增序列，避免后续新增数据主键碰撞。

进一步比较历史源结构与当前本地目标结构，目标新增字段已为 81 个（含五个订单日期字段），且发现 6 个“旧库允许 NULL、目标要求非空”的字段：`invoice_items.quantity`、`invoice_orders.expedited_shipping`、`invoice_orders.gift_box`、`invoice_staff_allocations.commission_percent`、`orders.items_count`、`orders.visible_order_id`。这是结构差异，不代表旧数据实际存在 NULL；预检会查询实际空值数。若有空值，先决定兼容方案，不用 0 或空字符串静默替换。服务器当前结果仍以现场预检和快照演练为准。

旧 `prepare.py` 写死了本地容器、库名、临时目录，包含 `TRUNCATE` 和导入历史备份 schema 的逻辑，**不要在服务器直接执行**。上次用 `setval` 校正序列，这类变更并不随普通事务回滚，正式迁移需安排独立验证与失败恢复，不能声称仅靠事务就能回滚全部影响。

## 本次范围

- 明确列入旧系统 38 张 public 业务表，包含订单、人工调整、人工删除标记、规则汇率、统计、发票、PayPal、采购、仓库和附件记录。运行状态类表如 `background_jobs` 需要检查，不能让导入的旧任务被新服务意外执行。
- 保留目标 `users`、`roles`、`permissions`、`role_permissions`、`user_roles`、`api_tokens`、`audit_logs`、`migrations`。
- 不迁移旧 `knex_migrations`、`knex_migrations_lock` 或 `saveb_release_backup_*` 历史备份表。
- 源库没有的目标表保留，包括新业务功能表及 Collector schema，不因缺少来源表而清空。
- 源库新增或目标缺失的字段、类型变化、无默认值的必填字段必须有明确映射；不通过忽略错误跳过表或静默丢字段。
- 若目标候选业务表已经有数据，预检列为阻断项，需要判断是否为初始化数据或新系统人工数据；不自动清空。

订单新日期字段 `source_created_at`、`payment_time`、`completed_time`、`source_updated_at`、`legacy_accounting_time` 允许为空，旧快照没有这些字段也可以按明确列清单导入。先原样保留旧 `order_time` 和 `raw`；数据校验通过后再独立预览日期修正，不在导入时猜测创建日期。

## 服务器只读预检

将本次 API 脚本与文档提交并 push 后，在服务器执行。只需宿主机 Python 3 和现有 Docker，**不必重新构建应用镜像**。

```bash
cd /home/admin_chen/www/saveb-api
git pull --ff-only origin main &&
python3 scripts/legacy_import_preflight.py \
  --source-container saveb-erp-phase4-task2-a823095-20260718-190342-postgres-1 \
  --target-container saveb-infra-postgres-1 \
  --output "/home/admin_chen/www/backups/legacy-preflight-$(date +%Y%m%d-%H%M%S)-$$"
```

脚本在两库执行只读事务，读取结构与计数；不停止旧服务，不创建临时库，不修改数据、索引或外键。数据库口令仅在容器内从现有环境读取，不需要再配置数据库密码。每条 SQL 最多等待 120 秒，锁等待最多 5 秒；超时停止，不绕过失败继续判定。

输出目录权限受 `umask 077` 保护，包含：

| 文件 | 内容 |
|---|---|
| `summary.txt` | 可直接粘贴的摘要和阻断项 |
| `report.json` | 38 张候选表计数、目标新增字段的兼容建议、历史用户外键、唯一索引 |
| `source-structure.json` | 源库列名、类型、默认值、约束和索引 |
| `target-structure.json` | 目标库对应结构 |

没有导出业务行或账号密码。每个库的计数使用独立只读快照，结构查询与计数也是不同事务；旧库持续使用时报告仅用于准备映射，不能将报告计数当作最终导入验收基准。

`blocked_for_mapping` 表示有需处理项；`requires_snapshot_rehearsal` 只表示初步列映射无阻断，**不代表数据已经迁入或所有约束通过**。唯一值重复、CHECK、历史用户关联、附件和序列都要在真实快照演练中验证。

## 附件和后续正式导入

已确认两个独立附件卷：

- 旧：`saveb-erp-phase4-task2-a823095-20260718-190342_attachments`，挂载 `/data/attachments`。
- 新：`saveb-production-attachments`，挂载 `/data/attachments`。

不要把新系统直接指向旧卷，不删除或改动旧文件。旧卷只读读取，按快照附件记录中的相对路径复制到新卷，并检查 SHA256；目标同路径文件若内容不同，列为冲突，不直接覆盖。旧系统运行时文件复制与数据库不是原子快照，缺失或发生变化的文件必须重试并报告，不能将数据库导入成功当作附件也完整。

预检后，按当前结构生成明确导入计划，再备份目标数据库与附件，将源一致性快照和目标备份恢复到隔离临时库演练。演练通过后在新系统的维护窗口导入同一源快照；只暂停新系统写入与采集，旧系统继续服务。正式导入前再次检查目标是否出现新数据或结构变化。

验收须覆盖：全部选定表原字段内容、记录数、人工调整和删除标记、主键/唯一键、业务外键、历史用户引用策略、业务序列、RBAC 前后校验和附件文件。全部完成后才启用 Collector。后续再导入旧系统新增数据时，应使用单独的增量合并方案，不能重复执行全量覆盖脚本。
