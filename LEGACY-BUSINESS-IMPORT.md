# 旧 ERP → 新 API：业务数据快照迁移

本次来源为 `saveb-erp-phase4-task2-a823095-20260718-190342-postgres-1` 的 `phase4` 库；目标为 `saveb-infra-postgres-1` 的 `saveb` 库。旧后台继续运行，本次仅导入导出快照中的已有数据，不是停机切换，也不是持续同步。快照开始后提交的变更不保证包含在本次导入中。

当前已有只读预检、隔离库演练及独立正式导入入口。预检脚本没有写入模式；演练脚本只写随机临时库；正式入口 `legacy_import_apply.py` 默认只检查，显式加 `--apply` 才写入。服务器是否已执行，以实际输出为准。

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

### 预检无阻断后：数据库演练

把新脚本提交并 push 后，在服务器执行；无需重新构建 Docker 镜像：

```bash
cd /home/admin_chen/www/saveb-api
git pull --ff-only origin main &&
python3 scripts/legacy_import_rehearsal.py \
  --source-container saveb-erp-phase4-task2-a823095-20260718-190342-postgres-1 \
  --target-container saveb-infra-postgres-1 \
  --output "/home/admin_chen/www/backups/legacy-rehearsal-$(date +%Y%m%d-%H%M%S)-$$"
```

该命令会导出源业务快照及目标全库备份，在**目标 PostgreSQL 容器内**创建 `saveb_rehearsal_src_<随机值>` 和 `saveb_rehearsal_trial_<随机值>` 两个数据库，恢复备份并试导入。需要目标数据库用户具有创建数据库权限；当前官方 PostgreSQL 镜像初始化用户通常具备该权限。脚本失败时不会切换到正式库重试。

演练保护与兼容处理：

1. 来源导出只读；仅导出 38 张业务表及其依赖序列。旧 `orders.visible_order_id` 的 `saveb_visible_order_id_seq` 是独立序列，必须显式包含，不能只靠 `pg_dump -t orders`。
2. 目标完整备份在隔离库真实恢复，包含现有 RBAC。临时源库不恢复依赖旧 RBAC 的 post-data 约束；业务导入按**目标约束**验证。
3. 从恢复后的固定源快照重新预检；目标候选业务表非空即停止。导入没有 `TRUNCATE`、`DELETE` 或 CASCADE 清理。
4. 使用明确列名导入，在一个事务内验证源字段内容、唯一索引、CHECK、业务外键及受保护表。仅历史数据到 `public.users` 的外键允许因孤立引用保留 `NOT VALID`，其他业务外键必须验证通过；报告列出实际未验证项。
5. 除 RBAC 外，目标所有未参与导入的普通业务表和 Collector 表都做前后校验。业务序列使用事务性的 `ALTER SEQUENCE ... RESTART`，下一个值高于已导入主键、来源已分配值及目标原序列位置；保留未参与迁移的序列。
6. 生成 `verification.json`、`summary.txt`、`SHA256SUMS`，保留两个临时库供排查。`trial-only.sql` 内有准确的临时数据库名称校验，误传正式库时第一步拒绝；不要删除这个保护来手工导入。

报告只包含计数、结构与校验值。备份、`copy-data/` 及 `private-errors.log` 可能包含业务内容或账号密码哈希，只保留在私有备份目录，不提交 Git、不公开粘贴。失败时先发终端摘要或 `failure.json`，不要直接贴原始错误日志。

演练通过仍然没有迁入正式库，也没有复制附件。下一阶段使用本次已校验的同一快照准备正式导入和附件复制；不重复从不断变化的旧库取数冒充同一次快照。旧后台可以继续使用，演练后新发生的变更不在该快照内。

本地已用 2026-09-07 留存的真实源快照与当前 API 结构做隔离库验证：38 张业务表、23,128 条记录的原字段内容匹配，39 张未参与导入的表保持不变，21 个业务序列校验通过。仅 `background_jobs_creator_fk`、`order_user_overrides_updated_by_user_fk`、`workflow_events_actor_fk` 保留历史用户引用兼容状态。此结果是本地回归证据，不是本次服务器数据量或迁移结果；本次没有计入旧 Knex 与历史备份表，所以总数不同于早期 45 表导入记录。

失败场景也已验证：仅在隔离测试库故意添加与旧数据冲突的 `client_order_id` 唯一索引，导入按预期失败；事务回滚后 38 张候选表仍为空，受保护表、外键定义和序列状态保持原样。历史用户引用统计查询已验证能返回孤立引用数量，不输出具体用户 UUID。

### 附件

已确认两个独立附件卷：

- 旧：`saveb-erp-phase4-task2-a823095-20260718-190342_attachments`，挂载 `/data/attachments`。
- 新：`saveb-production-attachments`，挂载 `/data/attachments`。

不要把新系统直接指向旧卷，不删除或改动旧文件。旧卷只读读取，按快照附件记录中的相对路径复制到新卷，并检查 SHA256；目标同路径文件若内容不同，列为冲突，不直接覆盖。旧系统运行时文件复制与数据库不是原子快照，缺失或发生变化的文件必须重试并报告，不能将数据库导入成功当作附件也完整。

预检后，按当前结构生成明确导入计划，再备份目标数据库与附件，将源一致性快照和目标备份恢复到隔离临时库演练。演练通过后在新系统的维护窗口导入同一源快照；只暂停新系统写入与采集，旧系统继续服务。正式导入前再次检查目标是否出现新数据或结构变化。

## 本次服务器演练通过后：正式导入

用户已确认本次服务器演练通过：38 表、23,775 行、16 张受保护表、21 个业务序列。材料目录为 `/home/admin_chen/www/backups/legacy-rehearsal-20260911-110739-90176`，临时源库为 `saveb_rehearsal_src_227550333e91`，临时目标库为 `saveb_rehearsal_trial_227550333e91`。保留这些文件和临时库，正式入口需要读取同一快照的附件清单。

将正式入口脚本与 PHP 附件助手提交并 push 后，在服务器执行。无需重新构建镜像；脚本复用正在运行的 API 镜像 ID作为文件复制助手，不拉取镜像、不运行应用入口。执行期间不要通过新后台、Navicat 或其他客户端修改新库。

```bash
cd /home/admin_chen/www/saveb-api
git pull --ff-only origin main &&
python3 scripts/legacy_import_apply.py \
  --snapshot /home/admin_chen/www/backups/legacy-rehearsal-20260911-110739-90176 \
  --output "/home/admin_chen/www/backups/legacy-apply-$(date +%Y%m%d-%H%M%S)-$$" \
  --apply
```

省略 `--apply` 时，只校验材料、目标结构/空业务表以及附件，不暂停服务或写入正式库。每次用新的 output 目录，不覆盖前一次报告。

正式执行过程：

1. 校验演练材料 SHA256、38 张表完整性及目标容器/库名。目标结构有变化、业务表已非空时拒绝导入。
2. 检查旧附件卷与新附件卷对应关系。按快照中的附件路径和 SHA256 核对来源文件及目标已有文件；缺失、内容冲突、路径越界或目标符号链接会停止，此时新服务尚未暂停。
3. 记录并暂停 `saveb-api-production` 和 `saveb-collector-production` 当前运行的容器，绝不停止旧 ERP/禅道/基础设施。Admin 静态页仍可访问，但 API 会暂时不可用。
4. 再次检查新库，备份当前完整数据库与当前新附件卷，保存 `BACKUP-SHA256SUMS`；重新记录当前 RBAC 等受保护表，保留演练后新增的授权与令牌。
5. 逐文件复制到新附件卷并校验；已有同内容文件保留，同路径不同内容不覆盖。新文件归 `www-data` 所有。附件文件复制不是数据库事务的一部分，后续 SQL 失败时已复制文件会保留，不自动删除原有文件。
6. 导入明确列清单，在单个事务内检查目标业务表为空、原字段内容一致、保护表不变、业务约束以及序列。允许的历史用户外键策略与演练一致。对保护表加锁，阻止导入期间被并发修改。
7. 复核序列，记录历史用户孤立引用数量，恢复本次暂停的服务并等待健康检查。未运行的 Collector 不会被顺带启动。

成功时终端显示“正式导入完成”，刷新 `http://192.168.11.84:13000/dashboard/overview` 验收；使用新系统原有账号和密码。原日期数据先按旧快照保留，日期修正和历史补采后续独立处理。

### 失败如何判断

- 附件检查失败：查看 `attachments-check.json` 的 `errors` 和失败项；没有暂停服务，也没有导入。不要用空文件占位。
- 暂停之后失败：新服务保持停止，先查看 `apply-state.json`、`failure.json`，不要自动重跑或直接恢复整库。`private-errors.log` 可能包含业务值，不公开粘贴。
- `status=database_commit_started` 且 `database_committed=null`：提交结果不确定，需先核实目标数据。不能据此认定已经回滚。
- `database_committed=true`：数据库已提交，可能是后续验证或健康检查失败；不要再次全量导入。
- `database_committed=false` 且仍处于提交前阶段：尚未执行数据库导入，可能已有附件复制，按文件报告核对。
- 恢复服务只针对 `apply-state.json` 中记录的新容器，不能使用全局 Docker 重启或清理命令。

本地验证覆盖：真实历史数据的完整 SQL 导入、保护表锁、重复全量导入拒绝；文件只读检查、缺失/哈希不符/同路径冲突拒绝、路径越界和符号链接拒绝、正确复制及重复复制保留。没有在本地验证服务器网络访问或替用户执行服务器正式导入。

验收须覆盖：全部选定表原字段内容、记录数、人工调整和删除标记、主键/唯一键、业务外键、历史用户引用策略、业务序列、RBAC 前后校验和附件文件。全部完成后才启用 Collector。后续再导入旧系统新增数据时，应使用单独的增量合并方案，不能重复执行全量覆盖脚本。
