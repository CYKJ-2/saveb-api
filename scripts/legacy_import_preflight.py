#!/usr/bin/env python3
"""只读比较旧 ERP 与新 API；在 Linux 宿主机运行，仅依赖 Python 3 和 Docker。

不生成清表 SQL、不导入、不修改约束。报告只有结构和计数，不含密码或业务行。
预检不是迁移快照；正式导入仍须重新导出一致性快照并在临时库演练。
"""
import argparse
import json
import os
import subprocess
from datetime import datetime, timezone
from pathlib import Path


PROTECTED = frozenset({
    "users", "roles", "permissions", "role_permissions", "user_roles",
    "api_tokens", "audit_logs", "migrations",
})
FRAMEWORK = frozenset({"knex_migrations", "knex_migrations_lock"})
# 来自已核实的旧 ERP 业务表；新出现的来源表必须先审查，不能自动扩大范围。
BUSINESS = frozenset("""
attachments background_jobs daily_stats exchange_rates idempotency_keys
influencer_domains influencer_order_links influencers invoice_adjustments
invoice_items invoice_operation_logs invoice_orders invoice_staff_allocations
legacy_dashboard_days legacy_import_items operation_cases order_items
order_staff_allocations order_staff_performance_projection order_status_observations
order_user_overrides orders paypal_accounts paypal_balance_entries paypal_reviews
paypal_withdrawals pending_completion_operations procurement_removed_orders
procurement_tasks purchase_task_items purchase_tasks shipment_tracking_events
site_classification_reclassifications system_state warehouse_receipts warehouse_records
warehouse_shipments workflow_events
""".split())

METADATA_SQL = """
SELECT json_build_object(
 'database', current_database(), 'server_version', current_setting('server_version'),
 'read_only', current_setting('transaction_read_only'),
 'observed_at', transaction_timestamp(),
 'schemas', (SELECT json_agg(nspname ORDER BY nspname) FROM pg_namespace
             WHERE nspname NOT LIKE 'pg_%' AND nspname <> 'information_schema'),
 'tables', (SELECT coalesce(json_agg(x ORDER BY x.name), '[]'::json) FROM (
   SELECT c.relname AS name, c.relkind AS kind,
    (SELECT json_agg(json_build_object(
      'name', a.attname, 'type', format_type(a.atttypid, a.atttypmod),
      'not_null', a.attnotnull, 'default', pg_get_expr(d.adbin, d.adrelid),
      'identity', a.attidentity, 'generated', a.attgenerated, 'position', a.attnum
     ) ORDER BY a.attnum)
     FROM pg_attribute a LEFT JOIN pg_attrdef d
       ON d.adrelid=a.attrelid AND d.adnum=a.attnum
     WHERE a.attrelid=c.oid AND a.attnum>0 AND NOT a.attisdropped) AS columns
   FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
   WHERE n.nspname='public' AND c.relkind IN ('r','p')
 ) x),
 'constraints', (SELECT coalesce(json_agg(x ORDER BY x.table_name,x.name), '[]'::json) FROM (
   SELECT c.relname AS table_name, k.conname AS name, k.contype AS type,
          pg_get_constraintdef(k.oid) AS definition, k.convalidated AS validated,
          rn.nspname AS reference_schema, rc.relname AS reference_table
   FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid
   JOIN pg_namespace n ON n.oid=c.relnamespace
   LEFT JOIN pg_class rc ON rc.oid=k.confrelid
   LEFT JOIN pg_namespace rn ON rn.oid=rc.relnamespace
   WHERE n.nspname='public'
 ) x),
 'indexes', (SELECT coalesce(json_agg(x ORDER BY x.tablename,x.indexname), '[]'::json)
   FROM (SELECT tablename,indexname,indexdef FROM pg_indexes WHERE schemaname='public') x)
);
"""


def identifier(value):
    return '"' + value.replace('"', '""') + '"'


def literal(value):
    return "'" + value.replace("'", "''") + "'"


def decode_results(output):
    # PostgreSQL 聚合的 JSON 可以含换行；不能按输出行拆 JSON。
    decoder, results = json.JSONDecoder(), []
    remaining = output.strip()
    while remaining:
        value, end = decoder.raw_decode(remaining)
        results.append(value)
        remaining = remaining[end:].lstrip()
    return results


def query(container, sql):
    # 口令仅在容器内从现有环境读取，不打印、不作为命令行参数传出。
    shell = '''
set -eu
export PGPASSWORD="${POSTGRESQL_PASSWORD:-${POSTGRES_PASSWORD:-}}"
export PGOPTIONS='-c default_transaction_read_only=on -c statement_timeout=120000 -c lock_timeout=5000'
exec psql -X -qAt -v ON_ERROR_STOP=1 \
  -U "${POSTGRESQL_USER:-${POSTGRES_USER:-}}" \
  -d "${POSTGRESQL_DATABASE:-${POSTGRES_DB:-}}"
'''
    sql = "BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY;\nSET LOCAL TIME ZONE 'UTC';\n" + sql + "\nCOMMIT;\n"
    result = subprocess.run(
        ["docker", "exec", "-i", container, "sh", "-c", shell],
        input=sql, capture_output=True, encoding="utf-8", timeout=300,
    )
    if result.returncode:
        # 服务端错误可能包含业务值，不将原始 stderr 放进可分享报告。
        raise RuntimeError("Database read failed for " + container + "; check connectivity/permissions or query timeout.")
    return decode_results(result.stdout)


def counts(container, names):
    # 每个库的所有计数处于同一个只读快照；两库之间不是同一时刻的快照。
    parts = ["SELECT json_build_object('table', " + literal(n) + ", 'rows', count(*)) FROM public."
             + identifier(n) + ";" for n in sorted(names)]
    return {r["table"]: r["rows"] for r in query(container, "\n".join(parts))} if parts else {}


def null_counts(container, source, target):
    dst = {t["name"]: {c["name"]: c for c in t["columns"]} for t in target["tables"]}
    sql = []
    for table in source["tables"]:
        name = table["name"]
        if name not in BUSINESS or name not in dst:
            continue
        for column in table["columns"]:
            field = column["name"]
            if field in dst[name] and dst[name][field]["not_null"] and not column["not_null"]:
                key = name + "." + field
                sql.append("SELECT json_build_object('field', " + literal(key)
                           + ", 'null_rows', count(*)) FROM public." + identifier(name)
                           + " WHERE " + identifier(field) + " IS NULL;")
    return {r["field"]: r["null_rows"] for r in query(container, "\n".join(sql))} if sql else {}


def compare(source, target, source_counts, target_counts, source_null_counts=None):
    src = {t["name"]: t for t in source["tables"]}
    dst = {t["name"]: t for t in target["tables"]}
    blockers, differences = [], []
    for name in sorted(PROTECTED - set(dst)):
        blockers.append({"table": name, "reason": "protected_target_table_missing_check_database_selection"})
    for name in sorted(set(src) - BUSINESS - PROTECTED - FRAMEWORK):
        blockers.append({"table": name, "reason": "unreviewed_source_table"})
    for name in sorted(BUSINESS & set(src)):
        if name not in dst:
            blockers.append({"table": name, "reason": "target_table_missing"})
            continue
        if target_counts.get(name, 0):
            blockers.append({"table": name, "reason": "target_has_business_rows", "rows": target_counts[name]})
        before = {c["name"]: c for c in src[name]["columns"]}
        after = {c["name"]: c for c in dst[name]["columns"]}
        for field, col in before.items():
            if field not in after:
                blockers.append({"table": name, "column": field, "reason": "source_column_missing_in_target"})
                continue
            new = after[field]
            if col["type"] != new["type"] or new.get("generated"):
                blockers.append({"table": name, "column": field, "reason": "type_or_generated_column_requires_mapping",
                                 "source_type": col["type"], "target_type": new["type"]})
            if new["not_null"] and not col["not_null"]:
                null_rows = (source_null_counts or {}).get(name + "." + field)
                if null_rows != 0:
                    blockers.append({"table": name, "column": field, "reason": "nullable_source_to_required_target", "source_null_rows": null_rows})
        for field in sorted(set(after) - set(before)):
            col = after[field]
            if col.get("generated") or col.get("identity"):
                action = "database_generated"
            elif field in ("created_at", "updated_at") and ("updated_at" if field == "created_at" else "created_at") in before:
                other = before["updated_at" if field == "created_at" else "created_at"]
                action = ("use_existing_row_timestamp_then_target_default"
                          if other["not_null"] or col["default"] is not None or not col["not_null"]
                          else "explicit_mapping_required")
            elif col["default"] is not None:
                action = "target_default"
            elif not col["not_null"]:
                action = "null"
            else:
                action = "explicit_mapping_required"
            differences.append({"table": name, "column": field, "type": col["type"], "action": action})
            if action == "explicit_mapping_required":
                blockers.append({"table": name, "column": field, "reason": "required_target_column_without_default"})
    # 约束和索引保留完整定义供演练审查；名称相同也不假定定义相同。
    foreign_keys = [c for c in target["constraints"]
                    if c["table_name"] in BUSINESS and c["type"] == "f"
                    and c.get("reference_schema") == "public" and c.get("reference_table") in PROTECTED]
    unique_reviews = [i for i in target.get("indexes", [])
                      if i["tablename"] in BUSINESS and "UNIQUE INDEX" in i["indexdef"]]
    return {
        "status": "blocked_for_mapping" if blockers else "requires_snapshot_rehearsal",
        "blockers": blockers, "target_added_columns": differences,
        "nullable_source_to_required_target_counts": source_null_counts or {},
        "historical_user_references_to_review": foreign_keys,
        "unique_indexes_to_check_on_snapshot": unique_reviews,
        "business_tables": [{"table": n, "source_rows": source_counts.get(n), "target_rows": target_counts.get(n)}
                            for n in sorted(BUSINESS & set(src))],
        "protected_target_tables": sorted(PROTECTED & set(dst)),
        "source_framework_tables_excluded": sorted(FRAMEWORK & set(src)),
        "target_only_tables_preserved": sorted(set(dst) - set(src)),
        "source_schemas_excluded": [n for n in source["schemas"] if n != "public"],
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-container", required=True)
    parser.add_argument("--target-container", required=True)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    if args.source_container == args.target_container:
        parser.error("Source and target containers must differ.")
    os.umask(0o077)
    # 新建目录，不覆盖前一次报告。目录放在 www 下的 backups 中。
    args.output.mkdir(parents=True, exist_ok=False)
    source = query(args.source_container, METADATA_SQL)[0]
    target = query(args.target_container, METADATA_SQL)[0]
    assert source["read_only"] == target["read_only"] == "on"
    source_counts = counts(args.source_container, {t["name"] for t in source["tables"]} & BUSINESS)
    target_counts = counts(args.target_container, {t["name"] for t in target["tables"]} - PROTECTED - FRAMEWORK)
    source_null_counts = null_counts(args.source_container, source, target)
    report = compare(source, target, source_counts, target_counts, source_null_counts)
    report.update({"generated_at": datetime.now(timezone.utc).isoformat(),
                   "source_database": source["database"], "target_database": target["database"],
                   "source_container": args.source_container, "target_container": args.target_container,
                   "note": "Read-only preflight only. No dump, data import, file copy, or schema changes were performed."})
    for name, value in [("source-structure.json", source), ("target-structure.json", target), ("report.json", report)]:
        (args.output / name).write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    lines = ["只读迁移预检（未执行导入）", "来源: " + source["database"] + "；目标: " + target["database"],
             "业务表: " + str(len(report["business_tables"])), "需处理阻断项: " + str(len(report["blockers"])),
             "目标新增字段: " + str(len(report["target_added_columns"])),
             "历史用户外键待核对: " + str(len(report["historical_user_references_to_review"]))]
    lines += [json.dumps(item, ensure_ascii=False) for item in report["blockers"]]
    lines += ["完整字段、约束和索引在 source-structure.json / target-structure.json；兼容计划在 report.json。",
              "预检通过也必须在临时库按真实快照演练；唯一约束、CHECK 和外键尚未进行数据级验证。"]
    summary = "\n".join(lines) + "\n"
    (args.output / "summary.txt").write_text(summary, encoding="utf-8")
    print(summary)


if __name__ == "__main__":
    main()
