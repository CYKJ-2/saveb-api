<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * 增加 SA 页面内个人业绩的操作权限，保持现有角色授权和菜单名称。
     *
     * @return void 幂等写入一个操作权限，不新增菜单
     */
    public function up(): void
    {
        $parent = Permission::where('code', 'business.sa_sales')->first();
        if ($parent) {
            Permission::firstOrCreate(['code' => 'business.sa_sales.personal'], [
                'name' => 'View Personal Performance', 'name_zh' => '查看个人业绩详情', 'parent_id' => $parent->id,
                'type' => 'action', 'action' => 'custom', 'level' => 3, 'status' => 1, 'is_menu_visible' => false,
            ]);
        }
    }

    /**
     * 回滚时保留权限，避免删除已分配的角色授权。
     *
     * @return void 不更改已有权限
     */
    public function down(): void
    {
    }
};
