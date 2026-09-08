<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        $root = Permission::firstOrCreate(['code' => 'dashboard'], ['name' => 'Home','name_zh' => '首页','parent_id' => 0,'type' => 'menu','path' => '/dashboard','component' => 'Layout','level' => 1,'status' => 1,'is_menu_visible' => true]);
        $menu = Permission::firstOrCreate(['code' => 'dashboard.order_management'], [
            'name' => 'Order Management','name_zh' => '订单管理','parent_id' => $root->id,'type' => 'menu',
            'path' => '/dashboard/order-management','component' => 'dashboard/order-management/index',
            'icon' => 'ShoppingCartOutlined','level' => 2,'status' => 1,'sort' => 20,'is_menu_visible' => true,
        ]);
        $nodes = ['list' => '查询订单','export' => '导出订单','testing' => '查看测试订单','update' => '调整订单客服 / 确认完成','create' => '创建订单','delete' => '删除订单'];
        foreach (['overview' => '成交概览','currencies' => '币种汇总','sales-trend' => '销售趋势','categories' => '销售分类','influencers' => '达人排行','staff' => '客服分摊统计'] as $key => $label) {
            $nodes['statistics.' . $key] = $label;
        }
        foreach ($nodes as $key => $label) {
            Permission::firstOrCreate(['code' => 'system.order.' . $key], [
                'parent_id' => $menu->id,'name' => $label,'name_zh' => $label,'type' => 'action',
                'action' => in_array($key, ['list','export','update','create','delete']) ? $key : 'custom',
                'level' => 3,'status' => 1,'is_menu_visible' => false,'resource' => 'orders',
            ]);
        }
    }

    public function down(): void
    { /* Retain explicit role grants on rollback. */
    }
};
