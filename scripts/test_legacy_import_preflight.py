"""验证迁移预检不会把字段缺失、类型变化或已有业务数据误判为可直接导入。"""
import copy
import unittest

from legacy_import_preflight import PROTECTED, compare, decode_results, identifier, literal


def column(name, type_name="text", not_null=False, default=None):
    return {"name": name, "type": type_name, "not_null": not_null,
            "default": default, "identity": "", "generated": ""}


def structure(columns):
    return {"tables": [{"name": "orders", "columns": columns}],
            "schemas": ["public"], "constraints": [], "indexes": []}


class CompatibilityTests(unittest.TestCase):
    def setUp(self):
        self.source = structure([column("order_id", not_null=True), column("created_at", "timestamp with time zone", True)])
        self.target = copy.deepcopy(self.source)
        self.target["tables"] += [{"name": n, "columns": []} for n in PROTECTED]

    def report(self, counts=None):
        return compare(self.source, self.target, {"orders": 5}, counts or {"orders": 0})

    def test_column_order_and_nullable_new_dates_need_no_positional_mapping(self):
        self.target["tables"][0]["columns"].reverse()
        self.target["tables"][0]["columns"].append(column("source_created_at", "timestamp with time zone"))
        result = self.report()
        self.assertEqual(result["blockers"], [])
        self.assertEqual(result["target_added_columns"][0]["action"], "null")
        self.assertEqual(result["status"], "requires_snapshot_rehearsal")

    def test_missing_source_field_is_not_silently_discarded(self):
        self.source["tables"][0]["columns"].append(column("legacy_only"))
        self.assertEqual(self.report()["blockers"][0]["reason"], "source_column_missing_in_target")

    def test_type_and_nullable_changes_block(self):
        self.target["tables"][0]["columns"][0]["type"] = "uuid"
        self.source["tables"][0]["columns"][1]["not_null"] = False
        reasons = {b["reason"] for b in self.report()["blockers"]}
        self.assertIn("type_or_generated_column_requires_mapping", reasons)
        self.assertIn("nullable_source_to_required_target", reasons)

    def test_required_new_field_without_default_blocks(self):
        self.target["tables"][0]["columns"].append(column("new_required", not_null=True))
        self.assertEqual(self.report()["blockers"][0]["reason"], "required_target_column_without_default")

    def test_time_fallback_uses_existing_time_but_does_not_invent_missing_time(self):
        self.target["tables"][0]["columns"].append(column("updated_at", "timestamp with time zone", True))
        self.assertEqual(self.report()["target_added_columns"][0]["action"], "use_existing_row_timestamp_then_target_default")
        self.source["tables"][0]["columns"][1]["not_null"] = False
        self.assertIn("required_target_column_without_default", {b["reason"] for b in self.report()["blockers"]})

    def test_existing_target_business_and_unknown_source_table_block(self):
        self.source["tables"].append({"name": "new_business_table", "columns": []})
        reasons = {b["reason"] for b in self.report({"orders": 1})["blockers"]}
        self.assertEqual(reasons, {"unreviewed_source_table", "target_has_business_rows"})

    def test_rbac_framework_and_local_only_tables_are_not_imported(self):
        self.source["tables"] += [{"name": n, "columns": []} for n in ["users", "knex_migrations"]]
        self.target["tables"].append({"name": "online_spreadsheets", "columns": []})
        result = self.report()
        self.assertEqual([t["table"] for t in result["business_tables"]], ["orders"])
        self.assertIn("online_spreadsheets", result["target_only_tables_preserved"])
        self.assertEqual(result["source_framework_tables_excluded"], ["knex_migrations"])

    def test_database_selection_and_identifier_escaping(self):
        self.target["tables"] = self.target["tables"][:1]
        self.assertEqual(len(self.report()["blockers"]), len(PROTECTED))
        self.assertEqual(identifier('a"b'), '"a""b"')
        self.assertEqual(literal("a'b"), "'a''b'")

    def test_multiline_postgresql_json_and_multiple_results(self):
        self.assertEqual(decode_results(' {"tables": [\n {"name": "orders"}\n]}\n{"rows": 2}\n'),
                         [{"tables": [{"name": "orders"}]}, {"rows": 2}])

    def test_not_null_tightening_uses_observed_null_count(self):
        self.source["tables"][0]["columns"][0]["not_null"] = False
        result = compare(self.source, self.target, {"orders": 5}, {}, {"orders.order_id": 0})
        self.assertEqual(result["blockers"], [])
        result = compare(self.source, self.target, {"orders": 5}, {}, {"orders.order_id": 2})
        self.assertEqual(result["blockers"][0]["source_null_rows"], 2)


if __name__ == "__main__":
    unittest.main()
