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

        // 兼容原本地启动脚本，实际安装逻辑与全新服务器完全一致。
        return $this->call('server:database-init', ['--force' => true]);
    }
}
