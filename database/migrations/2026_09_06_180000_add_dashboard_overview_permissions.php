<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        $root = Permission::firstOrCreate(['code' => 'dashboard'], ['name' => 'Home','name_zh' => '首页','parent_id' => 0,'type' => 'menu','path' => '/dashboard','component' => 'Layout','level' => 1,'status' => 1,'is_menu_visible' => true]);
        $page = Permission::firstOrCreate(['code' => 'dashboard.overview'], ['name' => 'Overview','name_zh' => '首页概览','parent_id' => $root->id,'type' => 'menu','path' => '/dashboard/overview','component' => 'dashboard/index','level' => 2,'status' => 1,'is_menu_visible' => true]);
        $modules = ['overview' => '核心指标与付款状态','sales_trend' => '销售趋势','categories' => '订单分类','influencers' => '达人排行','staff' => '客服业绩','recent_orders' => '最近订单','currencies' => '币种统计','paypal' => 'PayPal 收支','exchange_rates' => '汇率参考','system_status' => '数据覆盖状态','spreadsheets' => '在线表格'];
        foreach ($modules as $key => $label) {
            Permission::firstOrCreate(['code' => 'dashboard.overview.' . $key], [
                'name' => $label,'name_zh' => $label,'parent_id' => $page->id,'type' => 'action','action' => 'custom',
                'level' => 3,'status' => 1,'is_menu_visible' => false,
            ]);
        }
    }

    public function down(): void
    { /* Keep existing role grants intact. */
    }
};
