#!/usr/bin/env python3
"""导出当前快照，只在新建隔离库中演练；没有向正式库 apply 的入口。

宿主机 Python 3 + Docker 即可运行。备份和报告只写指定目录；不会停服务、
覆盖正式数据库、复制附件或删除数据库。所有写入连接均校验随机临时库名。
"""
import argparse
import hashlib
import json
import os
import re
import subprocess
import sys
import uuid
from datetime import datetime, timezone
from pathlib import Path

from legacy_import_preflight import BUSINESS, METADATA_SQL, compare, decode_results, identifier as qi, literal as qs


AUTH = '''set -eu
export PGPASSWORD="${POSTGRESQL_PASSWORD:-${POSTGRES_PASSWORD:-}}"
db_user="${POSTGRESQL_USER:-${POSTGRES_USER:-}}"
db_name="${POSTGRESQL_DATABASE:-${POSTGRES_DB:-}}"
'''


def save_json(path, data):
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


class Database:
    def __init__(self, container, log, database=None, writable=False):
        self.container, self.log, self.database = container, log, database
        self.writable = writable
        if writable and not re.fullmatch(r"saveb_rehearsal_(src|trial)_[a-f0-9]{12}", database or ""):
            raise ValueError("Writes are only allowed to a generated rehearsal database.")

    def run(self, command, args=(), data=None, stdin=None, stdout=None, readonly=True):
        if not readonly and not self.writable:
            raise ValueError("Refusing write connection to a live database.")
        # command 来自本文件常量，数据库名/路径用独立 argv 传递，绝不拼到 shell。
        options = "export PGOPTIONS='-c default_transaction_read_only=on -c lock_timeout=5000'\n" if readonly else ""
        script = AUTH + options + 'if [ -n "$1" ]; then db_name="$1"; fi\nshift\n' + command
        with self.log.open("ab") as errors:
            result = subprocess.run(["docker", "exec", "-i", self.container, "sh", "-c", script,
                                     "rehearsal", self.database or "", *args],
                                    input=data, stdin=stdin, stdout=stdout or subprocess.PIPE,
                                    stderr=errors, timeout=3600)
        if result.returncode:
            raise RuntimeError("Docker/PostgreSQL operation failed; details are in private-errors.log (may contain data; do not paste unredacted).")
        return result.stdout

    def sql(self, sql, write=False):
        if write and not self.writable:
            raise ValueError("Refusing SQL writes to a live database.")
        if not write:
            sql = "BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY;\nSET LOCAL TIME ZONE 'UTC';\n" + sql + "\nCOMMIT;"
        result = self.run('exec psql -X -qAt -v ON_ERROR_STOP=1 -U "$db_user" -d "$db_name"',
                          data=sql.encode("utf-8"), readonly=not write)
        return decode_results(result.decode("utf-8"))

    def metadata(self):
        return self.sql(METADATA_SQL)[0]

    def dump(self, path, tables=None):
        args = ["-Fc", "--lock-wait-timeout=5000"]
        if tables is not None:
            args += [part for name in sorted(tables) for part in ("-t", "public." + name)]
            # -t table 不保证带出未 OWNED BY 的 nextval 序列（旧 visible_order_id 即如此）。
            for sequence in sequence_inventory(self):
                if any(ref["schema"] == "public" and ref["table"] in tables for ref in sequence["refs"]):
                    args += ["-t", full_name(sequence["schema"], sequence["name"])]
        with path.open("xb") as stream:
            self.run('exec pg_dump -U "$db_user" -d "$db_name" "$@"', args, stdout=stream)
        if not path.stat().st_size:
            raise RuntimeError("Empty snapshot.")
        with path.open("rb") as stream:
            self.run('exec pg_restore --list', stdin=stream, stdout=subprocess.DEVNULL)

    def create(self):
        if not self.writable:
            raise ValueError("Not a rehearsal database.")
        # 不使用 --if-exists，不删除同名库；随机名若已存在立即失败。
        self.run('exec createdb -U "$db_user" --template=template0 "$db_name"', readonly=False)

    def restore(self, path, source=False):
        if not self.writable:
            raise ValueError("Refusing restore to live database.")
        # 源业务快照不含 RBAC，暂不恢复依赖 users 的外键/索引；源行和列保持原样。
        sections = [("--section=pre-data",), ("--section=data",)] if source else [()]
        for section in sections:
            with path.open("rb") as stream:
                self.run('exec pg_restore --exit-on-error --no-owner --no-privileges -U "$db_user" -d "$db_name" "$@"',
                         section, stdin=stream, readonly=False, stdout=subprocess.DEVNULL)


def full_name(schema, table):
    return qi(schema) + "." + qi(table)


def fingerprint_sql(schema, table, columns=None):
    fields = ",".join(map(qi, columns)) if columns else "*"
    return ("SELECT json_build_object('rows',count(*),'checksum',md5(coalesce(string_agg(h,'' ORDER BY h),''))) "
            "FROM (SELECT md5(row_to_json(r)::text) h FROM (SELECT " + fields + " FROM "
            + full_name(schema, table) + ") r) checks")


def inventory(db):
    return db.sql("""SELECT json_build_object('schema',n.nspname,'table',c.relname)
      FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
      WHERE c.relkind IN ('r','p') AND n.nspname NOT LIKE 'pg_%'
      AND n.nspname <> 'information_schema' ORDER BY n.nspname,c.relname;""")


def row_counts(db, metadata):
    return {t["name"]: db.sql("SELECT to_json(count(*)) FROM public." + qi(t["name"]) + ";")[0]
            for t in metadata["tables"]}


def null_counts(db, source, target):
    dst = {t["name"]: {c["name"]: c for c in t["columns"]} for t in target["tables"]}
    result = {}
    for table in source["tables"]:
        name = table["name"]
        if name not in dst or name not in BUSINESS:
            continue
        for col in table["columns"]:
            key = col["name"]
            if key in dst[name] and dst[name][key]["not_null"] and not col["not_null"]:
                result[name + "." + key] = db.sql("SELECT to_json(count(*)) FROM public." + qi(name)
                    + " WHERE " + qi(key) + " IS NULL;")[0]
    return result


def sequence_inventory(db):
    items = db.sql("""SELECT json_build_object('schema',n.nspname,'name',s.relname,
      'table_schema',tn.nspname,'table',t.relname,'column',a.attname,
      'increment',q.seqincrement,'max',q.seqmax,'min',q.seqmin)
      FROM pg_class s JOIN pg_namespace n ON n.oid=s.relnamespace
      JOIN pg_sequence q ON q.seqrelid=s.oid
      LEFT JOIN pg_depend d ON d.classid='pg_class'::regclass AND d.objid=s.oid
       AND d.refclassid='pg_class'::regclass AND d.deptype IN ('a','i')
      LEFT JOIN pg_class t ON t.oid=d.refobjid LEFT JOIN pg_namespace tn ON tn.oid=t.relnamespace
      LEFT JOIN pg_attribute a ON a.attrelid=t.oid AND a.attnum=d.refobjsubid
      WHERE s.relkind='S' AND n.nspname NOT LIKE 'pg_%' ORDER BY n.nspname,s.relname;""")
    for item in items:
        refs = db.sql("""SELECT json_build_object('schema',n.nspname,'table',t.relname,'column',a.attname)
          FROM pg_depend d JOIN pg_attrdef ad ON d.classid='pg_attrdef'::regclass AND ad.oid=d.objid
          JOIN pg_class t ON t.oid=ad.adrelid JOIN pg_namespace n ON n.oid=t.relnamespace
          JOIN pg_attribute a ON a.attrelid=t.oid AND a.attnum=ad.adnum
          WHERE d.refclassid='pg_class'::regclass AND d.refobjid="""
          + qs(full_name(item["schema"], item["name"])) + "::regclass ORDER BY n.nspname,t.relname,a.attname;")
        if item["table"] is not None:
            owner = {"schema": item["table_schema"], "table": item["table"], "column": item["column"]}
            if owner not in refs:
                refs.append(owner)
        item["refs"] = sorted(refs, key=lambda r: (r["schema"], r["table"], r["column"]))
        item.update(db.sql("SELECT json_build_object('last',last_value,'called',is_called) FROM "
                           + full_name(item["schema"], item["name"]) + ";")[0])
    return items


def is_business_sequence(item):
    return bool(item["refs"]) and all(r["schema"] == "public" and r["table"] in BUSINESS for r in item["refs"])


def historical_reference_counts(db):
    constraints = db.sql("""SELECT json_build_object('table',t.relname,'constraint',k.conname,
       'match',k.confmatchtype,'columns',(
         SELECT json_agg(json_build_object('child',a.attname,'parent',b.attname) ORDER BY u.ord)
         FROM unnest(k.conkey,k.confkey) WITH ORDINALITY u(childnum,parentnum,ord)
         JOIN pg_attribute a ON a.attrelid=k.conrelid AND a.attnum=u.childnum
         JOIN pg_attribute b ON b.attrelid=k.confrelid AND b.attnum=u.parentnum))
       FROM pg_constraint k JOIN pg_class t ON t.oid=k.conrelid
       JOIN pg_namespace n ON n.oid=t.relnamespace
       WHERE k.contype='f' AND NOT k.convalidated AND n.nspname='public'
       AND k.confrelid='public.users'::regclass ORDER BY t.relname,k.conname;""")
    for item in constraints:
        if item["table"] not in BUSINESS:
            continue
        present = (" OR " if item["match"] == "f" else " AND ").join(
            "c." + qi(pair["child"]) + " IS NOT NULL" for pair in item["columns"])
        match = " AND ".join("c." + qi(pair["child"]) + "=u." + qi(pair["parent"]) for pair in item["columns"])
        item["orphan_rows"] = db.sql("SELECT to_json(count(*)) FROM public." + qi(item["table"])
           + " c WHERE (" + present + ") AND NOT EXISTS(SELECT 1 FROM public.users u WHERE " + match + ");")[0]
    return [item for item in constraints if item["table"] in BUSINESS]


def sequence_plan(source_sequences, target_sequences, source_db):
    source = {(s["schema"], s["name"]): s for s in source_sequences}
    result = []
    for target in target_sequences:
        if not is_business_sequence(target):
            if any(r["schema"] == "public" and r["table"] in BUSINESS for r in target["refs"]):
                raise ValueError("Sequence is shared with a protected table; manual mapping required.")
            continue
        old = source.get((target["schema"], target["name"]))
        if old is None or old["refs"] != target["refs"] or target["increment"] != 1 or old["increment"] != 1:
            raise ValueError("Sequence ownership/increment needs explicit review: " + target["name"])
        maxima = [source_db.sql("SELECT coalesce(to_json(max(" + qi(ref["column"]) + ")), 'null'::json) FROM public."
                               + qi(ref["table"]) + ";")[0] for ref in target["refs"]]
        highest = max((v for v in maxima if v is not None), default=None)
        next_value = max(target["min"], target["last"] + int(target["called"]),
                         old["last"] + int(old["called"]), highest + 1 if highest is not None else target["min"])
        if next_value > target["max"]:
            raise ValueError("Sequence exhausted: " + target["name"])
        result.append({"schema": target["schema"], "sequence": target["name"],
                       "references": target["refs"], "next": next_value})
    return result


def copy_columns(source_table, target_table):
    before = {c["name"]: c for c in source_table["columns"]}
    names = list(before)
    expressions = [qi(n) for n in names]
    for col in target_table["columns"]:
        name = col["name"]
        other = "updated_at" if name == "created_at" else "created_at"
        if name in before or name not in ("created_at", "updated_at") or other not in before:
            continue
        if before[other]["type"] != col["type"]:
            raise ValueError("Timestamp fallback types differ: " + source_table["name"])
        default = col["default"]
        expression = qi(other)
        if not before[other]["not_null"]:
            if default is not None:
                if default.lower() not in ("current_timestamp", "now()"):
                    raise ValueError("Timestamp default requires explicit mapping.")
                expression = "coalesce(" + qi(other) + ",CURRENT_TIMESTAMP)"
            elif col["not_null"]:
                raise ValueError("No safe timestamp fallback.")
        names.append(name)
        expressions.append(expression)
    return names, expressions


def build_trial_sql(database, source, target, expected, protected, sequences, copy_dir, reset_tables=None):
    # SQL 自身也绑定随机临时库名；即使被误传给正式 psql，第一句就拒绝。
    if not re.fullmatch(r"saveb_rehearsal_trial_[a-f0-9]{12}", database):
        raise ValueError("Invalid trial database.")
    return build_empty_database_import_sql(database, source, target, expected, protected, sequences, copy_dir, reset_tables)


def build_empty_database_import_sql(database, source, target, expected, protected, sequences, copy_dir, reset_tables=None):
    """默认仅空业务表；专用替换入口可传审查清单，在同一事务内重置后导入。"""
    tables = {t["name"]: t for t in source["tables"]}
    targets = {t["name"]: t for t in target["tables"]}
    selected = sorted(tables)
    if set(selected) != BUSINESS:
        raise ValueError("Snapshot must contain exactly the reviewed 38 business tables.")
    if reset_tables is not None:
        from legacy_replace_plan import validate_reset_tables
        validate_reset_tables(reset_tables)
        if {(p['schema'], p['table']) for p in protected} & {(p['schema'], p['table']) for p in reset_tables}:
            raise ValueError('Replacement overlaps protected tables.')
    foreign_keys = [c for c in target["constraints"] if c["table_name"] in BUSINESS and c["type"] == "f"]
    sql = [r"\set ON_ERROR_STOP on", "BEGIN;", "SET LOCAL TIME ZONE 'UTC';", "SET LOCAL search_path=public,pg_catalog;", "SET LOCAL lock_timeout='5s';",
           "DO $guard$ BEGIN IF current_database() <> " + qs(database)
           + " THEN RAISE EXCEPTION 'Import database mismatch'; END IF; END $guard$;"]
    locked = [full_name(p['schema'], p['table']) for p in reset_tables] if reset_tables else ["public." + qi(n) for n in selected]
    sql.append("LOCK TABLE " + ",".join(locked) + " IN ACCESS EXCLUSIVE MODE;")
    if protected:
        sql.append("LOCK TABLE " + ",".join(full_name(p["schema"], p["table"]) for p in protected) + " IN SHARE MODE;")
    if reset_tables:
        # 与 COPY、内容校验在同一事务。RESTRICT 遇到清单外依赖立即拒绝，不扩大清理范围。
        sql.append("TRUNCATE TABLE " + ",".join(locked) + " CONTINUE IDENTITY RESTRICT;")
    for name in selected:
        sql.append("DO $empty$ BEGIN IF EXISTS(SELECT 1 FROM public." + qi(name)
                   + ") THEN RAISE EXCEPTION 'Nonempty business table: " + name + "'; END IF; END $empty$;")
    for fk in foreign_keys:
        sql.append("ALTER TABLE public." + qi(fk["table_name"]) + " DROP CONSTRAINT " + qi(fk["name"]) + ";")
    for name in selected:
        names, _ = copy_columns(tables[name], targets[name])
        sql.append(r"\copy public." + qi(name) + " (" + ",".join(map(qi, names)) + ") FROM "
                   + qs(copy_dir + "/" + name + ".copy"))
    for fk in foreign_keys:
        base = "ALTER TABLE public." + qi(fk["table_name"])
        definition = re.sub(r"\s+NOT VALID$", "", fk["definition"])
        sql.append(base + " ADD CONSTRAINT " + qi(fk["name"]) + " " + definition + " NOT VALID;")
        validate = base + " VALIDATE CONSTRAINT " + qi(fk["name"]) + ";"
        if fk.get("reference_schema") == "public" and fk.get("reference_table") == "users":
            # 仅历史用户孤立引用可保留 NOT VALID；其他业务外键一律必须验证通过。
            sql.append("DO $history$ BEGIN " + validate + " EXCEPTION WHEN foreign_key_violation THEN "
                       "RAISE NOTICE 'Historical user reference retained: " + fk["name"] + "'; END $history$;")
        else:
            sql.append(validate)
    for name in selected:
        check = fingerprint_sql("public", name, [c["name"] for c in tables[name]["columns"]])
        value = qs(json.dumps(expected[name])) + "::jsonb"
        sql.append("DO $verify$ BEGIN IF (" + check + ")::jsonb IS DISTINCT FROM " + value
                   + " THEN RAISE EXCEPTION 'Source content mismatch: " + name + "'; END IF; END $verify$;")
    for item in protected:
        check = fingerprint_sql(item["schema"], item["table"])
        sql.append("DO $protected$ BEGIN IF (" + check + ")::jsonb IS DISTINCT FROM "
                   + qs(json.dumps(item["fingerprint"])) + "::jsonb THEN RAISE EXCEPTION 'Protected table changed'; END IF; END $protected$;")
    for item in reset_tables or []:
        if item['schema'] == 'public' and item['table'] in BUSINESS:
            continue
        sql.append("DO $reset$ BEGIN IF EXISTS(SELECT 1 FROM " + full_name(item['schema'], item['table'])
                   + ") THEN RAISE EXCEPTION 'Replacement reset table not empty'; END IF; END $reset$;")
    # ALTER SEQUENCE RESTART 为事务性操作；不用不可回滚的 setval。
    for item in sequences:
        sql.append("ALTER SEQUENCE " + full_name(item["schema"], item["sequence"])
                   + " RESTART WITH " + str(item["next"]) + ";")
    sql.append("COMMIT;")
    return "\n".join(sql) + "\n"


def sha256(path):
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def rehearse(source_live, target_live, output, replace_business=False):
    run_id = uuid.uuid4().hex[:12]
    src_name, trial_name = "saveb_rehearsal_src_" + run_id, "saveb_rehearsal_trial_" + run_id
    src = Database(target_live.container, target_live.log, src_name, writable=True)
    trial = Database(target_live.container, target_live.log, trial_name, writable=True)
    state = {"status": "started", "source_export_requested_at": datetime.now(timezone.utc).isoformat(),
             "source_container": source_live.container, "target_container": target_live.container,
             "source_database": source_live.metadata()["database"], "target_database": target_live.metadata()["database"],
             "staging_database": src_name, "trial_database": trial_name,
             "live_target_written": False, "attachments_copied": False,
             "import_mode": "replace-business" if replace_business else "empty-business"}
    save_json(output / "state.json", state)
    print("1/6 导出旧库 38 张业务表快照，并备份新库。", flush=True)
    meta = source_live.metadata()
    from legacy_import_preflight import PROTECTED, FRAMEWORK
    if {t["name"] for t in meta["tables"]} - PROTECTED - FRAMEWORK != BUSINESS:
        raise ValueError("Source business table inventory changed; run preflight again.")
    source_live.dump(output / "source-business.dump", BUSINESS)
    state["source_dump_completed_at"] = datetime.now(timezone.utc).isoformat()
    target_live.dump(output / "target-before.dump")
    print("2/6 新建两个隔离临时库，真实恢复来源快照和目标备份。", flush=True)
    src.create()
    src.restore(output / "source-business.dump", source=True)
    trial.create()
    trial.restore(output / "target-before.dump")
    source, target = src.metadata(), trial.metadata()
    reset_tables = None
    if replace_business:
        from legacy_replace_plan import replacement_plan
        plan = replacement_plan(inventory(trial))
        reset_tables = plan['reset']
        save_json(output / 'replacement-plan.json', plan)
    save_json(output / "source-structure.json", source)
    save_json(output / "target-structure.json", target)
    compatibility = compare(source, target, row_counts(src, source), row_counts(trial, target), null_counts(src, source, target))
    if replace_business:
        compatibility['replacement_rows'] = [b for b in compatibility['blockers'] if b['reason'] == 'target_has_business_rows']
        compatibility['blockers'] = [b for b in compatibility['blockers'] if b['reason'] != 'target_has_business_rows']
    save_json(output / "compatibility.json", compatibility)
    if compatibility["blockers"]:
        raise ValueError("Snapshot compatibility blocked; see compatibility.json.")
    print("3/6 记录受保护表及序列，并生成带明确列名的 COPY 数据。", flush=True)
    protected = []
    for item in inventory(trial):
        if reset_tables is not None and item in reset_tables:
            continue
        if item["schema"] == "public" and item["table"] in BUSINESS:
            continue
        protected.append(dict(item, fingerprint=trial.sql(fingerprint_sql(item["schema"], item["table"]) + ";")[0]))
    original_sequences = sequence_inventory(trial)
    sequences = sequence_plan(sequence_inventory(src), original_sequences, src)
    expected = {}
    data_dir = output / "copy-data"
    data_dir.mkdir()
    target_tables = {t["name"]: t for t in target["tables"]}
    for table in source["tables"]:
        name = table["name"]
        expected[name] = src.sql(fingerprint_sql("public", name, [c["name"] for c in table["columns"]]) + ";")[0]
        _, expressions = copy_columns(table, target_tables[name])
        copy_sql = "BEGIN READ ONLY; SET LOCAL TIME ZONE 'UTC'; COPY (SELECT " + ",".join(expressions)
        copy_sql += " FROM public." + qi(name) + ") TO STDOUT; COMMIT;"
        with (data_dir / (name + ".copy")).open("xb") as stream:
            src.run('exec psql -X -qAt -v ON_ERROR_STOP=1 -U "$db_user" -d "$db_name"',
                    data=copy_sql.encode("utf-8"), stdout=stream)
    container_dir = "/tmp/saveb-rehearsal-" + run_id
    subprocess.run(["docker", "cp", str(data_dir), target_live.container + ":" + container_dir], check=True,
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    sql = build_trial_sql(trial_name, source, target, expected, protected, sequences, container_dir, reset_tables)
    (output / "trial-only.sql").write_text(sql, encoding="utf-8")
    save_json(output / "expected-business.json", expected)
    save_json(output / "protected-before.json", protected)
    save_json(output / "sequence-plan.json", sequences)
    print("4/6 仅在临时库试导入，校验原字段、唯一约束、CHECK 和外键。", flush=True)
    # psql 的 COPY/NOTICE 输出不含业务行，不做 JSON 解析。
    trial.run('exec psql -X -qAt -v ON_ERROR_STOP=1 -U "$db_user" -d "$db_name"',
              data=sql.encode("utf-8"), readonly=False, stdout=subprocess.DEVNULL)
    print("5/6 导入后复核业务、保护表和所有序列。", flush=True)
    verified = []
    for table in source["tables"]:
        name = table["name"]
        actual = trial.sql(fingerprint_sql("public", name, [c["name"] for c in table["columns"]]) + ";")[0]
        if actual != expected[name]:
            raise ValueError("Content verification failed: " + name)
        verified.append({"table": name, "rows": actual["rows"], "match": True})
    for item in protected:
        if trial.sql(fingerprint_sql(item["schema"], item["table"]) + ";")[0] != item["fingerprint"]:
            raise ValueError("Protected table changed.")
    after = sequence_inventory(trial)
    if [s for s in after if not is_business_sequence(s)] != [s for s in original_sequences if not is_business_sequence(s)]:
        raise ValueError("Protected sequence changed.")
    by_name = {(s["schema"], s["name"]): s for s in after}
    for item in sequences:
        actual = by_name[item["schema"], item["sequence"]]
        if actual["last"] != item["next"] or actual["called"]:
            raise ValueError("Sequence verification failed.")
    unresolved = [f for f in trial.metadata()["constraints"] if f["table_name"] in BUSINESS
                  and f["type"] == "f" and not f["validated"]]
    if any(f.get("reference_schema") != "public" or f.get("reference_table") != "users" for f in unresolved):
        raise ValueError("Unvalidated business foreign key.")
    report = {"status": "database_rehearsal_passed", "business": verified,
              "import_mode": state['import_mode'],
              "rows": sum(t["rows"] for t in verified), "protected_tables_unchanged": len(protected),
              "business_sequences_verified": len(sequences), "historical_user_constraints": unresolved,
              "historical_user_reference_counts": historical_reference_counts(trial),
              "attachments": "not_copied_or_verified", "live_target_written": False}
    save_json(output / "verification.json", report)
    print("6/6 保存校验文件和临时库名，保留用于后续正式导入准备。", flush=True)
    paths = [p for p in output.rglob("*") if p.is_file() and p.name not in ("private-errors.log", "state.json", "SHA256SUMS")]
    (output / "SHA256SUMS").write_text("".join(sha256(p) + "  " + p.relative_to(output).as_posix() + "\n" for p in sorted(paths)), encoding="utf-8")
    state.update(status="database_rehearsal_passed", completed_at=datetime.now(timezone.utc).isoformat())
    save_json(output / "state.json", state)
    summary = ("数据库演练通过；正式库未写入，附件尚未复制。\n业务表: " + str(len(verified))
               + "；记录: " + str(report["rows"]) + "；受保护表: " + str(len(protected))
               + "；已校验业务序列: " + str(len(sequences))
               + "\n保留 NOT VALID 的历史用户外键: " + str(len(unresolved))
               + "\n" + "\n".join(f["table_name"] + "." + f["name"] for f in unresolved)
               + "\n材料目录: " + str(output) + "\n临时源库: " + src_name + "\n临时目标库: " + trial_name + "\n")
    (output / "summary.txt").write_text(summary, encoding="utf-8")
    print(summary)
    return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-container", required=True)
    parser.add_argument("--target-container", required=True)
    parser.add_argument("--source-database", help="一般省略，从旧容器环境读取")
    parser.add_argument("--target-database", help="一般省略，从新容器环境读取")
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    os.umask(0o077)
    args.output.mkdir(parents=True, exist_ok=False)
    log = args.output / "private-errors.log"
    try:
        source = Database(args.source_container, log, args.source_database)
        target = Database(args.target_container, log, args.target_database)
        if source.container == target.container and source.database == target.database:
            raise ValueError("Source and target must differ.")
        rehearse(source, target, args.output)
    except Exception as error:
        save_json(args.output / "failure.json", {"status": "failed", "reason": str(error), "live_target_written": False})
        print("演练停止，未向正式库导入；保留快照和临时库供排查。原因: " + str(error), file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
