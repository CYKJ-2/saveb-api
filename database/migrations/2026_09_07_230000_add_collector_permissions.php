<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        $page = Permission::where('code', 'dashboard.overview')->firstOrFail();
        foreach (['collector_status' => '查看采集运行状态', 'collector_trigger' => '手动采集当天数据'] as $code => $label) {
            Permission::firstOrCreate(['code' => 'dashboard.overview.' . $code], [
                'name' => $label, 'name_zh' => $label, 'parent_id' => $page->id,
                'type' => 'action', 'action' => 'custom', 'level' => 3, 'status' => 1, 'is_menu_visible' => false,
            ]);
        }
    }

    public function down(): void
    {
    }
};
