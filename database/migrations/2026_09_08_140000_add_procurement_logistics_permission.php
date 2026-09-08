<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        $parent = Permission::where('code', 'business.procurement')->first();
        if ($parent) {
            Permission::firstOrCreate(['code' => 'business.procurement.logistics'], [
                'name' => 'Refresh logistics', 'name_zh' => '刷新物流', 'parent_id' => $parent->id,
                'type' => 'action', 'action' => 'custom', 'level' => 3, 'status' => 1, 'is_menu_visible' => false,
            ]);
        }
    }

    public function down(): void
    {
        // Preserve explicit role grants.
    }
};
