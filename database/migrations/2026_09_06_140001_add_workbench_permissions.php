<?php

use App\Models\OnlineSpreadsheet;
use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class () extends Migration {
    public function up(): void
    {
        $root = Permission::firstOrCreate(['code' => 'business'], ['name' => 'Business Workbench','name_zh' => '业务工作台','parent_id' => 0,'type' => 'menu','path' => '/workbench','component' => 'Layout','level' => 1,'status' => 1,'is_menu_visible' => true,'sort' => 20]);
        $pages = [
            'invoice' => ['Invoice 订单','invoice',['list' => '查询订单','create' => '录入订单','update' => '编辑订单','delete' => '删除订单','ocr' => '截图识别','logs' => '操作记录','export' => '导出操作记录']],
            'sa_sales' => ['SA 销售分析','sa-sales',['list' => '查看销售报表','export' => '导出销售报表']],
            'procurement' => ['采购工作台','procurement',['list' => '查询采购','statistics' => '采购统计','create' => '创建采购任务','update' => '更新采购任务','delete' => '移除采购任务','logs' => '操作记录','export' => '导出采购及日志']],
            'warehouse' => ['仓库工作台','warehouse',['list' => '查询仓库','update' => '质检与发货']],
            'influencer' => ['达人工作台','influencer',['list' => '查看网站目录','statistics' => '销售排行','create' => '添加网站归属','export' => '导出网站目录']],
            'paypal' => ['PayPal 余额监控','paypal',['list' => '查看账户','orders' => '查看收款订单','create' => '新增账户','balance' => '修正余额','review' => '更新审核次数','withdrawal' => '记录提现','export' => '导出账户记录']],
            'operations' => ['工作巡查','operations',['list' => '查看在线表格目录']],
        ];
        foreach ($pages as $key => [$name,$path,$actions]) {
            $menu = Permission::firstOrCreate(['code' => 'business.' . $key], ['name' => $name,'name_zh' => $name,'parent_id' => $root->id,'type' => 'menu','path' => '/workbench/' . $path,'component' => 'workbench/' . $path . '/index','level' => 2,'status' => 1,'is_menu_visible' => true]);
            foreach ($actions as $action => $label) {
                Permission::firstOrCreate(['code' => 'business.' . $key . '.' . $action], ['name' => $label,'name_zh' => $label,'parent_id' => $menu->id,'type' => 'action','action' => in_array($action, ['list','create','update','delete','export']) ? $action : 'custom','level' => 3,'status' => 1,'is_menu_visible' => false]);
            }
        }
        $file = database_path('data/online-spreadsheets.json');
        if (is_file($file)) {
            foreach (json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) as $sort => $row) {
                OnlineSpreadsheet::firstOrCreate(['source_key' => $row['id']], ['department' => $row['department'],'provider' => $row['provider'],'title_zh' => $row['titleZh'],'title_en' => $row['titleEn'],'description_zh' => $row['descriptionZh'],'description_en' => $row['descriptionEn'],'url' => $row['url'],'sort' => $sort,'active' => true]);
            }
        }
    }

    public function down(): void
    { /* Preserve existing grants and links. */
    }
};
