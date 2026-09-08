<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        $system = Permission::where('code', 'system')->firstOrFail();
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
