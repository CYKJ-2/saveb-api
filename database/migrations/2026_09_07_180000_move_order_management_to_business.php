<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /** 调整菜单归属和入口，不改权限 ID、状态或角色授权。 */
    public function up(): void
    {
        $businessId = DB::table('permissions')->where('code', 'business')->value('id');
        if ($businessId === null) {
            return;
        }

        DB::table('permissions')->where('code', 'dashboard.order_management')->update([
            'parent_id' => $businessId,
            'path' => '/workbench/order-management',
            'component' => 'workbench/order-management/index',
            'level' => 2,
            'sort' => 0,
            'updated_at' => now(),
        ]);
        DB::table('permissions')->where('code', 'business')->update([
            'name' => 'Business Management',
            'name_zh' => '业务管理',
            'updated_at' => now(),
        ]);
        DB::table('permissions')->where('code', 'dashboard.overview')->update([
            'name' => 'Home',
            'name_zh' => '首页',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $homeId = DB::table('permissions')->where('code', 'dashboard')->value('id');
        if ($homeId === null) {
            return;
        }

        DB::table('permissions')->where('code', 'dashboard.order_management')->update([
            'parent_id' => $homeId,
            'path' => '/dashboard/order-management',
            'component' => 'dashboard/order-management/index',
            'level' => 2,
            'sort' => 20,
            'updated_at' => now(),
        ]);
        DB::table('permissions')->where('code', 'business')->update([
            'name' => 'Business Workbench',
            'name_zh' => '业务工作台',
            'updated_at' => now(),
        ]);
        DB::table('permissions')->where('code', 'dashboard.overview')->update([
            'name' => 'Overview',
            'name_zh' => '首页概览',
            'updated_at' => now(),
        ]);
    }
};
