<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** 补齐原平台验货图片系统外链，只新增缺失菜单及超级管理员授权。 */
class InspectionMenuSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $menu = Permission::withTrashed()->firstOrCreate(['code' => 'inspection'], [
                'name' => 'Inspection Photo System',
                'name_zh' => '验货系统',
                'parent_id' => 0,
                'type' => 'menu',
                'path' => 'https://www.saveb-photos.com/admin',
                'component' => null,
                'icon' => 'Camera',
                'action' => 'custom',
                'level' => 1,
                'sort' => 90,
                'status' => 1,
                'hidden' => false,
                'is_menu_visible' => true,
            ]);

            $superAdmin = Role::where('code', 'super_admin')->first();
            if ($superAdmin && !$menu->trashed() && $menu->status === 1) {
                $superAdmin->permissions()->syncWithoutDetaching([$menu->id]);
            }
        });
    }
}
