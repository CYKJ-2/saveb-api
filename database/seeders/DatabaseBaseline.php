<?php

namespace Database\Seeders;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** 本地与首次服务器初始化共用结构基线，避免重复执行历史删表迁移。 */
class DatabaseBaseline
{
    public function install(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('初始化仅支持 PostgreSQL。');
        }
        $existing = DB::selectOne("SELECT count(*) AS total FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p', 'v', 'm', 'S', 'f')");
        if ((int) $existing->total !== 0) {
            throw new RuntimeException('目标 schema 已有表、视图或序列，拒绝初始化；不会覆盖现有数据。');
        }
        DB::unprepared(file_get_contents(database_path('schema/newsql-baseline.sql')));
        DB::statement('CREATE TABLE migrations (id serial PRIMARY KEY, migration varchar(255) NOT NULL, batch integer NOT NULL)');
        foreach ([
            '2026_09_04_180000_create_rbac',
            '2026_09_05_120000_create_orders',
            '2026_09_05_120001_relax_orders_columns_for_erp_sync',
            '2026_09_05_200000_recreate_business_tables_v3',
            '2026_09_05_210000_create_business_tables_v4_consolidated',
        ] as $migration) {
            DB::table('migrations')->insert(['migration' => $migration, 'batch' => 1]);
        }
    }
}
