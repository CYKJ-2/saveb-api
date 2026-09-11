<?php

namespace App\Console\Commands;

use Database\Seeders\DatabaseBaseline;
use Database\Seeders\RbacSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** 通用首次安装：只依赖源码及 .env，创建全部表、基础权限及管理员。 */
class InitializeServerDatabase extends Command
{
    protected $signature = 'server:database-init {--force : 明确执行首次空库初始化}';

    protected $description = '初始化空 PostgreSQL：全部结构、增量迁移及基础 RBAC，无需本地数据或快照';

    /**
     * 在单个事务中完成结构和基础种子，失败回滚，重复运行拒绝覆盖。
     *
     * @param DatabaseBaseline $baseline 本地与服务器共用的结构基线
     * @return int 成功返回 0，非空目标或校验失败返回 1
     */
    public function handle(DatabaseBaseline $baseline): int
    {
        if (!$this->option('force') || DB::getDriverName() !== 'pgsql') {
            $this->error('此命令仅支持 PostgreSQL，首次初始化须显式传入 --force。');

            return self::FAILURE;
        }
        try {
            DB::transaction(function () use ($baseline): void {
                DB::select("SELECT pg_advisory_xact_lock(hashtext('saveb-first-database-init'))");
                $baseline->install();
                if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
                    throw new RuntimeException('增量迁移失败，首次初始化已回滚。');
                }
                $seeder = app(RbacSeeder::class)->setContainer(app())->setCommand($this);
                $seeder->__invoke();
            });
        } catch (Throwable $exception) {
            // SQL 异常可能包含账号及密码哈希，不输出底层语句和绑定参数。
            $this->error($exception instanceof \Illuminate\Database\QueryException
                ? '数据库结构或关联校验失败，初始化已回滚；请检查目标代码和数据库结构。'
                : $exception->getMessage());

            return self::FAILURE;
        }
        $this->info('全部结构和基础 RBAC 已就绪。账号 super_admin，默认密码 123456，首次登录后修改密码。');
        $this->info('可以启动 API/Admin 使用空业务库；旧订单、规则和附件可随后单独迁入。');

        return self::SUCCESS;
    }
}
