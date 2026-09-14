# 服务器：用旧后台最新快照替换新后台业务数据

适用选择：**旧后台为准，替换新后台现有业务数据，保留新系统 RBAC。**

执行位置是服务器 `/home/admin_chen/www/saveb-api`。本地只修改脚本并提交 Git，不对本地业务数据库执行迁移。服务器 pull 后使用宿主机 Python 3 和已存在的 Docker 镜像，无需构建应用镜像、配置 GHCR 或重新初始化数据库。

## 范围

| 项目 | 行为 |
| --- | --- |
| 来源 | saveb-erp-phase4-task2-a823095-20260718-190342-postgres-1，phase4 库，只读导出 |
| 目标 | saveb-infra-postgres-1，saveb 库；校验生产容器、Compose 项目及材料目录 |
| 38 张旧业务表 | 用本次一致性快照替换，包括人工调整、删除标记、采购、仓库、发票、PayPal、规则、统计和附件记录 |
| 新系统独有业务表 | 已审查的 business_operation_logs、online_spreadsheets 和 6 张 analysis_* 表，存在时清空，旧系统没有可导入记录 |
| 保留表 | users、roles、permissions、role_permissions、user_roles、api_tokens、audit_logs、migrations；保留正式替换前的新值，不回退到演练时的授权 |
| Collector | 清空旧任务、原始任务归档、来源快照、覆盖记录、游标、对账/日期修复记录及缓存汇率等；保留 schema_versions 和 schedules，包括自动采集间隔 |
| 附件 | 先备份新卷；按快照路径和 SHA256 复制，同路径不同内容以旧快照为准；不改旧附件卷 |
| 未识别的 public / collector 表 | 停止并报告，不使用 CASCADE 扩大清理范围 |
| 其他 schema | 保留并校验；若外键阻止替换则停止，不绕过约束 |

具体清单写入 replacement-plan.json：reset 会被替换或清空，keep 保留。6 张分析表是 analysis_imports、analysis_categories、analysis_brands、analysis_suppliers、analysis_supplier_rules、analysis_procurement_rows。

新后台自行新增或修改的业务记录将被替换。备份包含替换前的新库和附件；新卷中没有被快照引用的旧文件暂不物理删除，其业务记录按快照替换。

## 1. 提交脚本，服务器拉取

一起提交并 push：新入口 legacy_replace.py、清单模块 legacy_replace_plan.py，以及更新的 legacy_import_apply.py、legacy_import_rehearsal.py、legacy_import_attachments.php；不要只上传入口文件。

服务器执行：

```bash
cd /home/admin_chen/www/saveb-api
git pull --ff-only origin main &&
python3 scripts/legacy_replace.py --help
```

原 legacy_import_apply.py 仍只接受空业务库，并拒绝使用替换模式的演练材料；无需绕过旧入口的保护。

## 2. 导出最新快照，在临时库演练

在同一个 SSH 终端执行并保留 SNAPSHOT_DIR：

```bash
cd /home/admin_chen/www/saveb-api
SNAPSHOT_DIR="/home/admin_chen/www/backups/legacy-replace-rehearsal-$(date +%Y%m%d-%H%M%S)-$$"
python3 scripts/legacy_replace.py prepare --output "$SNAPSHOT_DIR"
```

这一步只读导出旧库、备份新库，在目标 PostgreSQL 容器内创建两个隔离临时库，真实恢复后试替换并检查内容、约束、保护表及序列。旧后台、新后台继续运行；正式新库不替换，附件不复制。

成功应出现“数据库演练通过”。查看 summary.txt、replacement-plan.json、compatibility.json、verification.json。若出现未审查表或字段差异，先处理报告，不执行下一步。

“最新”指本次 pg_dump 一致性快照中的数据，旧后台后续变化不会自动加入。如果演练后间隔很久，应重新 prepare 并使用新材料。不要再用 9 月 11 日的旧快照目录。

## 3. 只检查附件和目标

使用刚才通过的同一快照：

```bash
python3 scripts/legacy_replace.py apply \
  --snapshot "$SNAPSHOT_DIR" \
  --output "/home/admin_chen/www/backups/legacy-replace-check-$(date +%Y%m%d-%H%M%S)-$$"
```

没有 --apply 不会暂停服务、覆盖附件或写正式数据库。再次核对目标结构、材料校验值、源附件；源文件缺失或与快照 SHA256 不符时停止。

通过后通知新后台使用者暂停操作，停止 Navicat 等客户端对新库的写入。旧后台可以继续使用；下一步会短暂停止新 API。

## 4. 正式替换一次

```bash
python3 scripts/legacy_replace.py apply \
  --snapshot "$SNAPSHOT_DIR" \
  --output "/home/admin_chen/www/backups/legacy-replace-apply-$(date +%Y%m%d-%H%M%S)-$$" \
  --apply
```

执行：校验 → 暂停新 API/Collector → 重新备份新库和新附件卷 → 按快照复制/替换附件 → 单事务替换及验证 → 恢复新 API 接口和原先运行的 Collector 接口。

数据库清理使用明确表清单的 TRUNCATE CONTINUE IDENTITY RESTRICT，与 COPY、内容及保护表校验位于同一事务。约束或校验失败回滚数据库替换，不执行 CASCADE，不删除表结构，不重置 RBAC。业务序列保持高于来源及目标已经分配的值。

API worker 和 Collector worker/history/maintenance/logistics/beat 默认保持停止，避免快照刚导入就被任务修改。列表记录在 apply-state.json 的 workers_kept_stopped。

Collector 旧任务 ID 已删除，Redis 中按旧 ID 投递的消息恢复消费时不会重放旧订单；维护消息恢复后按当前状态规划，无需全局清 Redis。API 的旧 Redis 业务队列需按下节处理后再开启 worker。

## 5. 验收后恢复任务

```bash
curl -f http://127.0.0.1:18088/up
curl -f http://192.168.11.84:13000/healthz
```

在新后台核对登录权限、旧系统最新日期的订单、采购/仓库/发票/PayPal、附件，并检查 verification.json。首页所选日期可能没有已完成订单，不能只凭首页总额判断导入结果。

当前 API 使用 redis/default 队列。在确认是新项目专用 Redis、尚未恢复新业务操作的维护窗口，丢弃迁移前待处理的新系统业务任务，再恢复 API worker；不清整个 Redis或登录令牌：

```bash
cd /home/admin_chen/www/saveb-api
export RELEASE_IMAGE=$(docker inspect --format '{{.Image}}' saveb-api-production-app-1)
docker compose -f docker-compose.server.yml exec -T app \
  php artisan queue:clear redis --queue=default --force &&
docker compose -f docker-compose.server.yml up -d --no-build --pull never --wait worker
```

如果修改过 REDIS_QUEUE 或使用其他队列，先按实际配置核对，不能以默认队列命令认定所有旧业务消息已清理。

再恢复 Collector 执行器，先不启动 Beat：

```bash
cd /home/admin_chen/www/saveb-collector
export RELEASE_IMAGE=$(docker inspect --format '{{.Image}}' saveb-collector-production-api-1)
docker compose -f docker-compose.server.yml --profile tools run \
  --rm --no-deps --pull never migrate python scripts/preflight.py &&
docker compose -f docker-compose.server.yml up \
  -d --no-build --pull never --wait api worker history maintenance logistics
```

首页手动采集当天，确认成功和数据正确后恢复定时：

```bash
docker compose -f docker-compose.server.yml up -d --no-build --pull never beat
```

如果原来 Collector 接口未运行，先按 [服务器启动说明](APPLICATION-START.md) 完成相应步骤，不依赖 inspect 获取不存在的容器。

## 失败与恢复

- prepare 失败：正式新库没替换，保留材料和临时库，查看 failure.json / compatibility.json。
- 附件检查失败：尚未开始正式写入；检查附件报告，不跳过缺失或哈希错误。
- 正式暂停后失败：新服务保持停止。先读 apply-state.json，避免旧进程继续写入。
- database_committed 为 null：可能在提交时断连，先核实库内数据，不能推断已回滚。
- database_committed 为 true：数据库已经替换，即使恢复服务失败，也不能立即再跑一次。
- 文件复制不在数据库事务内：SQL 回滚不会撤销已替换文件。依据本次 target-immediately-before.dump 和 attachments-before.tar.gz 配套制定恢复步骤，先判断提交状态，不自动覆盖新数据。

备份、SQL 和日志只保存在 /home/admin_chen/www/backups，不提交 Git。脚本不改旧后台、不停止旧容器、不改本地业务库，也不修改 Docker 全局配置。命令必须在服务器完成真实演练后才能确认适配现场数据；开发验证仅使用独立临时测试容器和虚构数据。
