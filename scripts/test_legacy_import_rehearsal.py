"""演练入口防误写、列名映射、序列兼容规则的回归检查。"""
import copy
import unittest
from pathlib import Path

from legacy_import_rehearsal import Database, build_trial_sql, copy_columns, sequence_plan


def col(name, required=False, default=None):
    return {"name": name, "type": "timestamp with time zone", "not_null": required, "default": default}


class RehearsalTests(unittest.TestCase):
    def test_write_connection_cannot_select_real_database(self):
        with self.assertRaises(ValueError):
            Database("postgres", Path("unused"), "saveb", writable=True)
        db = Database("postgres", Path("unused"), "saveb")
        with self.assertRaises(ValueError):
            db.sql("CREATE TABLE accidental (id int);", write=True)
        with self.assertRaises(ValueError):
            db.run("ignored", readonly=False)
        with self.assertRaises(ValueError):
            build_trial_sql("saveb", {}, {}, {}, [], [], "/tmp/none")

    def test_copy_maps_names_not_column_positions_and_preserves_old_values(self):
        source = {"name": "orders", "columns": [col("order_time"), col("created_at", True)]}
        target = {"columns": [col("updated_at", True, "CURRENT_TIMESTAMP"), col("created_at", True), col("order_time")]}
        names, expressions = copy_columns(source, target)
        self.assertEqual(names, ["order_time", "created_at", "updated_at"])
        self.assertEqual(expressions, ['"order_time"', '"created_at"', '"created_at"'])

    def test_nullable_timestamp_needs_defined_fallback(self):
        source = {"name": "orders", "columns": [col("created_at")]}
        target = {"columns": [col("updated_at", True)]}
        with self.assertRaises(ValueError):
            copy_columns(source, target)
        target["columns"][0]["default"] = "now()"
        self.assertEqual(copy_columns(source, target)[1][-1], 'coalesce("created_at",CURRENT_TIMESTAMP)')

    def test_independent_sequence_moves_past_source_allocation_and_row_maximum(self):
        class Snapshot:
            def sql(self, sql):
                return [500]
        source = {"schema": "public", "name": "saveb_visible_order_id_seq", "increment": 1,
                  "refs": [{"schema": "public", "table": "orders", "column": "visible_order_id"}],
                  "min": 1, "max": 10000, "last": 900, "called": True}
        target = copy.deepcopy(source)
        target.update(last=1, called=False)
        self.assertEqual(sequence_plan([source], [target], Snapshot())[0]["next"], 901)
        target["refs"].append({"schema": "public", "table": "users", "column": "id"})
        with self.assertRaises(ValueError):
            sequence_plan([source], [target], Snapshot())


if __name__ == "__main__":
    unittest.main()
