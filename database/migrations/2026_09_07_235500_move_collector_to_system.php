<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        // 空库按“结构迁移 → RBAC seed”初始化时，系统菜单可能还未建立。
        // 仅补齐缺失父节点，已有菜单属性及角色授权保持原值。
        $system = Permission::firstOrCreate(['code' => 'system'], [
            'name' => 'System Management', 'name_zh' => '系统管理',
            'parent_id' => 0, 'type' => 'menu', 'path' => '/system', 'component' => 'Layout',
            'icon' => 'SettingOutlined', 'level' => 1, 'status' => 1, 'sort' => 100,
            'is_menu_visible' => true,
        ]);
        // 保留节点 ID 与权限代码，已有角色授权继续生效；仅改变菜单归属和地址。
        Permission::where('code', 'dashboard.collector')->update([
            'parent_id' => $system->id,
            'path' => '/system/collector',
        ]);
    }

    public function down(): void
    {
        $dashboard = Permission::where('code', 'dashboard')->firstOrFail();
        Permission::where('code', 'dashboard.collector')->update([
            'parent_id' => $dashboard->id,
            'path' => '/dashboard/collector',
        ]);
    }
};
