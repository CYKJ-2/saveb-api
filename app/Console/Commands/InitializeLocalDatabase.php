<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** 按 newsql.md 初始化全新的本地 PostgreSQL，不覆盖已有数据。 */
class InitializeLocalDatabase extends Command
{
    protected $signature = 'local:database-init';

    protected $description = '仅在空的本地数据库中安装 newsql.md 基线、增量迁移和 RBAC 权限';

    public function handle(): int
    {
        if (!app()->environment('local') || DB::getDriverName() !== 'pgsql') {
            $this->error('此命令仅支持 APP_ENV=local 的 PostgreSQL。');

            return self::FAILURE;
        }

        $tableCount = DB::table('information_schema.tables')
            ->whereRaw('table_schema = current_schema()')
            ->where('table_type', 'BASE TABLE')
            ->count();

        if ($tableCount > 0) {
            $this->error('数据库已有表，拒绝初始化。已有基线请使用 migrate 和 db:seed --class=RbacSeeder。');

            return self::FAILURE;
        }

        DB::transaction(function (): void {
            DB::unprepared(file_get_contents(database_path('schema/newsql-baseline.sql')));
            DB::statement('CREATE TABLE migrations (id serial PRIMARY KEY, migration varchar(255) NOT NULL, batch integer NOT NULL)');

            // 基线已完整包含这五个旧版结构迁移；尤其不能再次执行旧版的删表重建逻辑。
            foreach ([
                '2026_09_04_180000_create_rbac',
                '2026_09_05_120000_create_orders',
                '2026_09_05_120001_relax_orders_columns_for_erp_sync',
                '2026_09_05_200000_recreate_business_tables_v3',
                '2026_09_05_210000_create_business_tables_v4_consolidated',
            ] as $migration) {
                DB::table('migrations')->insert(['migration' => $migration, 'batch' => 1]);
            }
        });

        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        return $this->call('db:seed', ['--class' => 'RbacSeeder', '--force' => true]);
    }
}
