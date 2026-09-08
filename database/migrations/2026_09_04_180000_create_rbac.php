<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 按 newsql.md 重建 RBAC 表结构（融合 menus + permissions）。
 *
 * 表：
 *   - users            §2.1 用户账户
 *   - api_tokens       §2.2 API 认证 Token
 *   - roles            §3.1 RBAC 角色
 *   - permissions      §3.2 融合"菜单 + 权限点"为一棵树
 *   - role_permissions §3.3 角色 ↔ 权限（菜单/按钮）
 *   - user_roles       §3.4 用户 ↔ 角色
 *   - audit_logs       §5   审计日志
 *
 * 设计核心（融合方案）：
 *   - 把原来的 menus 与 permissions 合并到一张 permissions 表
 *   - 字段 type 区分 'menu'(显示在导航) / 'action'(纯权限点)
 *   - parent_id 自引用，支持任意层级树形
 *   - role_permissions 既可以挂菜单节点（控制"能看什么菜单"），
 *     也可以挂 action（控制"能点哪些按钮/调哪些 API"）
 *   - 查询某用户可见菜单：WHERE type='menu' AND id IN (用户拥有的权限ID)
 *
 * 时间戳规范：
 *   - 类型 timestamptz(6)
 *   - created_at/updated_at: NOT NULL DEFAULT CURRENT_TIMESTAMP
 *   - deleted_at/last_used_at/expires_at: NULL（不设默认值，避免自动填充）
 *
 * 实现策略：
 *   - Schema::create() 用 Blueprint::timestampsTz() / softDeletesTz()，
 *     直接产出 timestamptz（框架已正确处理时区）
 *   - Laravel 默认产出 precision=0；用 fixTimestampPrecision6() 仅把精度提升到 6，
 *     不再做任何时区/默认值转换，因为全新建表里没有历史数据需要兼容
 */
return new class () extends Migration {
    /**
     * 把指定表的若干 timestamptz 列精度从 0 提升到 6，并按列名补默认值。
     *
     * Laravel Blueprint::timestampTz() 生成的列是 nullable 且无默认值，
     * 这与 newsql.md 期望的 `NOT NULL DEFAULT CURRENT_TIMESTAMP` 不一致。
     *
     * 每个需要默认值的列：
     *   1. 类型 timestamptz(6)
     *   2. SET DEFAULT CURRENT_TIMESTAMP
     *   3. ALTER COLUMN ... SET NOT NULL  （可选；这里保持 nullable 以兼容软删除）
     *
     * @param  string            $table         表名
     * @param  array<int,string> $columns       时间列名列表
     * @param  array<int,string> $withDefaults  需要默认值的列（其他列只改类型不补默认）
     */
    private function fixTimestampPrecision6(string $table, array $columns, array $withDefaults = []): void
    {
        $defaults = array_flip($withDefaults);
        foreach ($columns as $col) {
            $sql = "ALTER TABLE {$table} ALTER COLUMN {$col} TYPE timestamptz(6)";
            if (isset($defaults[$col])) {
                $sql .= ", ALTER COLUMN {$col} SET DEFAULT CURRENT_TIMESTAMP";
            }
            DB::statement($sql);
        }
    }

    public function up(): void
    {
        /* ─── 0. users (newsql.md §2.1) ─────────────── */
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id()->comment('主键，自增序列');
                $table->string('username', 64)->comment('登录用户名，唯一约束');
                $table->string('password_hash', 255)->comment('密码 bcrypt/argon2 哈希值，严禁明文');
                $table->string('display_name', 100)->comment('界面显示的用户名称');
                $table->string('staff_code', 64)->nullable()->comment('关联员工编码');
                $table->boolean('active')->default(true)->comment('账户是否启用；true=启用，false=禁用');
                $table->boolean('must_change_password')->default(false)->comment('下次登录是否强制要求修改密码');
                $table->timestampsTz();
                $table->softDeletesTz();
                $table->unique('username', 'users_username_unique');
            });
        }

        /* ─── 1. roles (newsql.md §3.1) ──────────────── */
        Schema::create('roles', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->string('code', 64)->unique()->comment('角色代码，全局唯一');
            $table->string('name', 100)->comment('角色显示名称（英文/默认）');
            $table->string('name_zh', 100)->nullable()->comment('角色中文显示名称（前端中英切换）');
            $table->string('description', 255)->nullable()->comment('角色描述（英文/默认）');
            $table->string('description_zh', 255)->nullable()->comment('角色中文描述');
            $table->smallInteger('status')->default(1)->comment('状态：1=启用，0=禁用');
            $table->boolean('is_system')->default(false)->comment('是否内置角色');
            $table->integer('sort')->default(0)->comment('排序权重');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['status', 'sort']);
        });

        /* ─── 2. permissions (融合菜单 + 权限点) ────────── */
        // 字段说明：
        //   type='menu'   : 在导航可见；用于前端渲染菜单树
        //   type='action' : 纯权限点；不出现在导航，仅用于后端 API 校验
        //   parent_id: 自引用树形结构，0 = 顶级
        Schema::create('permissions', function (Blueprint $table) {
            $table->id()->comment('主键，自增序列');
            $table->unsignedBigInteger('parent_id')->default(0)
                ->comment('父节点ID；0=顶级。菜单和action权限在同一棵树中');
            $table->string('code', 100)->unique()
                ->comment('权限代码，全局唯一。菜单如 system.user；动作如 user.create');
            $table->string('name', 100)->comment('显示名称（英文/默认）');
            $table->string('name_zh', 100)->nullable()->comment('中文显示名称（前端中英切换）');
            $table->string('type', 16)->default('menu')
                ->comment('节点类型：menu=导航菜单节点，action=纯权限点（不出现在导航）');
            $table->string('path', 255)->nullable()
                ->comment('前端路由路径，仅menu类型有意义，如 /system/users');
            $table->string('icon', 64)->nullable()->comment('菜单图标');
            $table->string('component', 255)->nullable()->comment('Vue组件路径');
            $table->string('action', 32)->default('custom')
                ->comment('操作类型：list/create/update/delete/export/custom。仅action类型有意义');
            $table->string('resource', 100)->nullable()
                ->comment('资源标识，用于细粒度的资源级权限控制');
            $table->smallInteger('level')->default(1)
                ->comment('树层级：1=模块，2=页面，3=按钮。冗余字段便于查询优化');
            $table->boolean('is_menu_visible')->default(true)
                ->comment('是否在侧边栏导航中显示。menu=true 通常为true；action=false');
            $table->boolean('hidden')->default(false)
                ->comment('隐藏菜单节点（仍可访问，仅不显示）');
            $table->integer('sort')->default(0)->comment('同级排序权重');
            $table->smallInteger('status')->default(1)->comment('状态：1=启用，0=禁用');
            $table->string('description', 255)->nullable()->comment('描述说明（英文/默认）');
            $table->string('description_zh', 255)->nullable()->comment('中文描述');
            $table->timestampsTz();
            $table->softDeletesTz();

            // 自引用树形索引
            $table->index(['parent_id', 'sort']);
            $table->index(['type', 'status']);
            // 兼容 newsql.md 的 idx_permissions_menu_status
            $table->index(['level', 'status'], 'permissions_level_status_index');
        });

        /* ─── 3. role_permissions (角色 ↔ 权限) ─────────── */
        // 既挂菜单（控制"能看什么菜单"），也挂 action（控制"能做什么操作"）
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->unsignedBigInteger('role_id')->comment('角色ID');
            $table->unsignedBigInteger('permission_id')->comment('权限ID（菜单或action）');
            $table->timestampsTz();

            $table->foreign('role_id')
                ->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id')
                ->references('id')->on('permissions')
                ->cascadeOnDelete();

            $table->unique(['role_id', 'permission_id']);
            $table->index('permission_id');
        });

        /* ─── 4. user_roles (用户 ↔ 角色) ──────────────── */
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->unsignedBigInteger('user_id')->comment('用户ID');
            $table->unsignedBigInteger('role_id')->comment('角色ID');
            $table->unsignedBigInteger('granted_by_user_id')->nullable()->comment('授权人用户ID');
            $table->timestampsTz();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('granted_by_user_id')->references('id')->on('users')->nullOnDelete();

            $table->unique(['user_id', 'role_id']);
            $table->index('role_id');
        });

        /* ─── 5. api_tokens (newsql.md §2.2) ─────────── */
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->unsignedBigInteger('user_id')->comment('所属用户ID');
            $table->string('token_hash', 64)->unique()->comment('Token明文SHA-256哈希');
            $table->string('name', 100)->nullable()->comment('Token标签');
            $table->jsonb('abilities')->nullable()->comment('Token权限范围');
            $table->string('ip', 45)->nullable()->comment('签发时客户端IP');
            $table->string('user_agent', 500)->nullable()->comment('签发时UA');
            $table->timestampTz('last_used_at')->nullable()->comment('最近使用时间');
            $table->timestampTz('expires_at')->nullable()->comment('过期时间；NULL=永不过期');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('user_id');
            $table->index('expires_at')->whereNotNull('expires_at');
        });

        /* ─── 6. audit_logs (newsql.md §5) ───────────── */
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->comment('操作用户ID');
            $table->string('action', 64)->comment('操作类型');
            $table->string('entity_type', 64)->comment('实体类型');
            $table->string('entity_id', 64)->nullable()->comment('实体ID');
            $table->string('ip', 45)->nullable()->comment('客户端IP');
            $table->text('details')->nullable()->comment('JSON格式操作详情');
            $table->timestampTz('created_at')->nullable()->comment('操作时间');
            $table->index('user_id');
            $table->index(['entity_type', 'entity_id']);
            $table->index('created_at');
        });

        /* ─── 7. Add users.role_id (newsql.md §2.1) ───── */
        if (! Schema::hasColumn('users', 'role_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id')
                    ->nullable()
                    ->after('must_change_password')
                    ->comment('RBAC角色外键');
                $table->foreign('role_id')->references('id')->on('roles')->nullOnDelete();
                $table->index('role_id');
            });
        }

        /* ─── 8. Seed built-in roles ─────────────────── */
        $now = now();
        DB::table('roles')->insert([
            ['id' => 1, 'code' => 'super_admin', 'name' => 'Super Administrator', 'description' => '拥有所有菜单和权限，不可删除。', 'status' => 1, 'is_system' => true, 'sort' => 0,  'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'code' => 'admin',       'name' => 'Administrator',       'description' => '默认管理员角色。',                   'status' => 1, 'is_system' => true, 'sort' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'code' => 'viewer',      'name' => 'Read-only Viewer',    'description' => '只读用户，无写入权限。',               'status' => 1, 'is_system' => true, 'sort' => 90, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::statement("SELECT setval(pg_get_serial_sequence('roles','id'), GREATEST((SELECT MAX(id) FROM roles), 1))");

        /* ─── 9. 把所有时间列精度提升到 (6) ────────────── */
        // Blueprint::timestampTz() 默认精度是 0、且无默认值；
        // newsql.md 要求 timestamptz(6) + created_at/updated_at 有 CURRENT_TIMESTAMP 默认值。
        // 全新表里没有历史数据，所以可以直接 ALTER TYPE，不需 USING 转换子句。
        // deleted_at / last_used_at / expires_at / audit_logs.created_at 保持 nullable，不设默认。
        $tzSpec = [
            'users'             => [['created_at', 'updated_at'], ['deleted_at']],
            'roles'             => [['created_at', 'updated_at'], ['deleted_at']],
            'permissions'       => [['created_at', 'updated_at'], ['deleted_at']],
            'role_permissions'  => [['created_at', 'updated_at'], []],
            'user_roles'        => [['created_at', 'updated_at'], []],
            'api_tokens'        => [['created_at', 'updated_at'], ['deleted_at', 'last_used_at', 'expires_at']],
            'audit_logs'        => [[], ['created_at']],
        ];
        foreach ($tzSpec as $table => [$withDefault, $noDefault]) {
            $this->fixTimestampPrecision6($table, array_merge($withDefault, $noDefault), $withDefault);
        }
    }

    public function down(): void
    {
        // 顺序：先去掉 users.role_id 这个指向 roles 的 FK，
        // 再按"从依赖到被依赖"逐张 drop，最后 drop roles
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'role_id')) {
            Schema::table('users', function (Blueprint $table) {
                try {
                    $table->dropForeign(['role_id']);
                } catch (\Throwable) {
                }
                try {
                    $table->dropIndex(['role_id']);
                } catch (\Throwable) {
                }
                $table->dropColumn('role_id');
            });
        }

        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('api_tokens');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');

        // users 由本迁移管理；如需保留请注释下行
        // Schema::dropIfExists('users');
    }
};
