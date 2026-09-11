<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

/** 从版本库安装测试表，不读取 public 中的业务表或共用其序列。 */
final class BusinessSchema
{
    /**
     * 在调用方已创建的隔离 schema 中准备业务表及运行时结构补丁。
     *
     * @param array<int, string> $tables 本组测试需要的基线表名
     * @return void 所有表、索引和自增序列均属于当前 rbac_test_* schema
     */
    public static function create(array $tables): void
    {
        $schema = DB::selectOne('SELECT current_schema() AS name')->name;
        if (getenv('RBAC_TEST_POSTGRES') !== '1' || !preg_match('/^rbac_test_[a-f0-9]{16}$/', $schema)) {
            throw new LogicException('Business fixtures require an isolated rbac_test_* schema.');
        }

        $manifest = json_decode(file_get_contents(database_path('schema/newsql-manifest.json')), true, 512, JSON_THROW_ON_ERROR);
        $baseline = file_get_contents(database_path('schema/newsql-baseline.sql'));
        // 剩余模块迁移还会补充附件 owner_user_id、PayPal version，并创建两张非基线表。
        $tables = array_unique([...$tables, 'procurement_tasks', 'procurement_removed_orders', 'warehouse_records', 'attachments', 'paypal_accounts']);
        $sequences = [];
        foreach ($tables as $table) {
            if (in_array($table, ['business_operation_logs', 'online_spreadsheets'], true)) {
                continue;
            }
            if (!preg_match('/^[a-z_]+$/', $table) || !isset($manifest[$table])) {
                throw new LogicException('Unknown business fixture table.');
            }
            $columns = implode(",\n", $manifest[$table]);
            preg_match_all("/nextval\\('([a-z_]+)'/", $columns, $matches);
            foreach ($matches[1] as $sequence) {
                if (!isset($sequences[$sequence])) {
                    if (!preg_match('/CREATE SEQUENCE\s+' . preg_quote($sequence, '/') . '\b[^;]*;/', $baseline, $definition)) {
                        throw new LogicException('Missing business fixture sequence definition.');
                    }
                    DB::statement($definition[0]);
                    $sequences[$sequence] = true;
                }
            }
            // 清单包含列、默认值和表约束；与原 LIKE 克隆一样，不额外创建跨模块外键。
            DB::statement('CREATE TABLE ' . $table . " (\n" . $columns . "\n)");
        }

        // 保留普通索引及部分唯一索引，避免测试结构放宽生产唯一性要求。
        preg_match_all('/CREATE (?:UNIQUE )?INDEX\s+\w+\s+ON\s+(\w+)\b[^;]*;/s', $baseline, $indexes, PREG_SET_ORDER);
        foreach ($indexes as $index) {
            if (in_array($index[1], $tables, true)) {
                DB::statement($index[0]);
            }
        }

        (require database_path('migrations/2026_09_06_140000_add_remaining_business_modules.php'))->up();

        $patches = [
            'warehouse_records' => ['2026_09_07_120000_add_local_schema_runtime_columns.php'],
            'attachments' => ['2026_09_07_130000_allow_independent_attachment_uploads.php'],
            'orders' => [
                '2026_09_07_140000_allow_legacy_client_order_ids.php',
                '2026_09_08_120000_separate_order_source_times.php',
            ],
        ];
        foreach ($patches as $table => $migrations) {
            if (in_array($table, $tables, true)) {
                foreach ($migrations as $migration) {
                    (require database_path('migrations/' . $migration))->up();
                }
            }
        }
    }
}
