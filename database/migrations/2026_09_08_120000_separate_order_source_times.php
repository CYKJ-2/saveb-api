<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /** 旧服务器导入可以省略全部新字段；历史数据修正由独立可预览脚本完成。 */
    public function up(): void
    {
        foreach (['source_created_at', 'payment_time', 'completed_time', 'source_updated_at', 'legacy_accounting_time'] as $column) {
            if (!Schema::hasColumn('orders', $column)) {
                Schema::table('orders', function (Blueprint $table) use ($column): void {
                    $table->timestampTz($column, 6)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['source_created_at', 'payment_time', 'completed_time', 'source_updated_at', 'legacy_accounting_time']);
        });
    }
};
