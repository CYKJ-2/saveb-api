<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** 只增补 Analysis 菜单与按钮权限，不覆盖用户已调整的其他菜单名称。 */
class AnalysisMenuSeeder extends Seeder
{
    /**
     * 在业务管理下创建分析菜单和查询、导入、导出权限。
     *
     * @return void 幂等补齐权限，仅为现有超级管理员追加新权限
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $business = Permission::where('code', 'business')->first();
            if (!$business) {
                return;
            }
            $menu = Permission::withTrashed()->firstOrCreate(['code' => 'business.analysis'], [
                'name' => 'Analysis', 'name_zh' => 'Analysis 分析', 'parent_id' => $business->id,
                'type' => 'menu', 'path' => '/workbench/analysis', 'component' => 'workbench/analysis/index',
                'icon' => 'DataAnalysis', 'action' => 'custom', 'level' => 2, 'sort' => 85,
                'status' => 1, 'hidden' => false, 'is_menu_visible' => true,
            ]);
            if ($menu->trashed()) {
                return;
            }
            $ids = [$menu->id];
            foreach (['list' => ['查看分析', 'View analysis'], 'import' => ['采集表格', 'Import workbooks'], 'export' => ['导出分析明细', 'Export analysis rows']] as $action => $names) {
                $permission = Permission::withTrashed()->firstOrCreate(['code' => 'business.analysis.' . $action], [
                    'name' => $names[1], 'name_zh' => $names[0], 'parent_id' => $menu->id,
                    'type' => 'action', 'path' => '', 'component' => null, 'icon' => null,
                    'action' => $action === 'list' ? 'list' : ($action === 'import' ? 'create' : 'export'),
                    'level' => 3, 'sort' => 0, 'status' => 1, 'hidden' => true, 'is_menu_visible' => false,
                ]);
                if (!$permission->trashed()) {
                    $ids[] = $permission->id;
                }
            }
            Role::where('code', 'super_admin')->first()?->permissions()->syncWithoutDetaching($ids);
        });
    }
}
