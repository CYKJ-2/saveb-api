<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // newsql.md 仓库表保留旧 ERP 字段，当前页面额外使用 version 做并发更新校验。
        if (!Schema::hasColumn('warehouse_records', 'version')) {
            Schema::table('warehouse_records', fn (Blueprint $table) => $table->integer('version')->default(1));
        }
    }

    public function down(): void
    {
        // 保留并发版本字段，避免回滚时影响已产生的业务数据。
    }
};
