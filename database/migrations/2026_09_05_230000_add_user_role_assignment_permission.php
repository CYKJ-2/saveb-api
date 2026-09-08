<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        $parent = DB::table('permissions')->where('code', 'system.user')->whereNull('deleted_at')->first();
        if (! $parent || DB::table('permissions')->where('code', 'system.user.assign_role')->exists()) {
            return;
        }
        DB::table('permissions')->insert([
            'parent_id' => $parent->id, 'code' => 'system.user.assign_role',
            'name' => 'Assign User Roles', 'name_zh' => '分配用户角色',
            'type' => 'action', 'action' => 'custom', 'level' => $parent->level,
            'is_menu_visible' => false, 'status' => 1, 'sort' => 50,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Keep explicit grants and their identifiers on rollback.
    }
};
