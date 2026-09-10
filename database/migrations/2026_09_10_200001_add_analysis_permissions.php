<?php

use Database\Seeders\AnalysisMenuSeeder;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    /**
     * 新增 Analysis 页面与操作权限，不重置已有菜单。
     *
     * @return void 为超级管理员增量授权
     */
    public function up(): void
    {
        (new AnalysisMenuSeeder())->run();
    }

    /**
     * 回滚时保留管理员已配置的菜单名称及授权。
     *
     * @return void 不删除权限数据
     */
    public function down(): void
    {
    }
};
