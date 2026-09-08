<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        // 线上 ERP 的客户端订单号不是唯一标识；唯一主订单号仍由 order_id 保证。
        // newsql.md 基线重新带入了旧约束，这里恢复既有 ERP 同步迁移的兼容行为。
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_client_order_id_unique');
    }

    public function down(): void
    {
        // 不能通过回滚删除或改写具有相同客户端订单号的历史订单。
    }
};
