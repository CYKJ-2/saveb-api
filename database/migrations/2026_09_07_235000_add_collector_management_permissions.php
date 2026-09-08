<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        $parent = Permission::where('code', 'dashboard')->firstOrFail();
        $page = Permission::firstOrCreate(['code' => 'dashboard.collector'], [
            'name' => 'Collection management', 'name_zh' => '采集管理', 'parent_id' => $parent->id,
            'type' => 'menu', 'path' => '/dashboard/collector', 'component' => 'dashboard/collector/index',
            'icon' => 'SyncOutlined', 'level' => 2, 'status' => 1, 'sort' => 20, 'is_menu_visible' => true,
        ]);
        foreach (['settings' => '设置采集间隔', 'collect' => '采集更新日期范围', 'reprocess' => '归档重算预览'] as $code => $name) {
            Permission::firstOrCreate(['code' => 'dashboard.collector.' . $code], [
                'name' => $name, 'name_zh' => $name, 'parent_id' => $page->id,
                'type' => 'action', 'action' => 'custom', 'level' => 3, 'status' => 1, 'is_menu_visible' => false,
            ]);
        }
    }

    public function down(): void
    {
    }
};
