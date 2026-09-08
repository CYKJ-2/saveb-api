<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        $parent = Permission::where('code', 'business.paypal')->first();
        if (!$parent) {
            return;
        }
        $actions = [
            'orders_export' => ['Export Received Orders', '下载收款订单'],
            'withdrawals' => ['View Withdrawal Records', '查看提款记录'],
            'statistics' => ['View Withdrawal Statistics', '查看提款统计'],
            'logs' => ['Download Change Log', '下载修改日志'],
        ];
        foreach ($actions as $action => [$name, $nameZh]) {
            Permission::firstOrCreate(['code' => 'business.paypal.' . $action], [
                'name' => $name,
                'name_zh' => $nameZh,
                'parent_id' => $parent->id,
                'type' => 'action',
                'action' => 'custom',
                'level' => 3,
                'status' => 1,
                'is_menu_visible' => false,
            ]);
        }
    }

    public function down(): void
    {
        // 不删除已由管理员明确授权的权限。
    }
};
