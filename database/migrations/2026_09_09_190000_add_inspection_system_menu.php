<?php

use Database\Seeders\InspectionMenuSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        (new InspectionMenuSeeder())->run();
    }

    public function down(): void
    {
        // 保留已配置的菜单名称及角色授权，回滚不删除用户数据。
    }
};
