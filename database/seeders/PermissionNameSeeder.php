<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** 同步内置权限的中英文名称，保留授权关联、状态及自定义节点。 */
class PermissionNameSeeder extends Seeder
{
    /** 由 saveb-admin/scripts/sync-permission-names.mjs 从页面语言包生成。 */
    private const NAMES = [
        'dashboard' => ['Sales Live Dashboard', '销售实况'],
        'dashboard.overview' => ['Sales Live Dashboard', '销售实况'],
        'business' => ['Business', '业务管理'],
        'system' => ['System', '系统管理'],
        'dashboard.order_management' => ['Order Management', '订单管理'],
        'dashboard.overview.overview' => ['Key Metrics / Payment Status', '核心指标 / 付款状态'],
        'dashboard.overview.sales_trend' => ['Sales Trend', '销售趋势'],
        'dashboard.overview.categories' => ['Order Categories', '订单分类'],
        'dashboard.overview.staff' => ['Offline Sales Associate Performance', '线下客服业绩'],
        'dashboard.overview.influencers' => ['Influencer Sales Ranking', '达人销售排行'],
        'dashboard.overview.recent_orders' => ['Recent Orders', '最近订单'],
        'dashboard.overview.collector_status' => ['Collector monitoring', '采集运行监控'],
        'dashboard.overview.collector_trigger' => ['Collect today’s data', '采集当天数据'],
        'dashboard.collector' => ['Collection management', '采集管理'],
        'dashboard.collector.settings' => ['Save interval', '保存间隔'],
        'dashboard.collector.collect' => ['Range update / Missing dates', '范围更新 / 仅补缺'],
        'dashboard.collector.reprocess' => ['Archive recalculation preview', '归档重算预览（不入库）'],
        'system.order.list' => ['Order Search', '订单查询'],
        'system.order.update' => ['Edit', '编辑'],
        'system.order.export' => ['Export All Results', '导出全部查询结果'],
        'system.order.testing' => ['Test Orders', '测试订单'],
        'system.order.statistics.overview' => ['Orders / Items / Sales', '成交订单 / 成交件数 / 销售额'],
        'system.order.statistics.currencies' => ['Sales by Currency', '币种销售汇总'],
        'system.order.statistics.sales-trend' => ['Sales Trend', '销售趋势'],
        'system.order.statistics.categories' => ['Sales Category', '销售分类'],
        'system.order.statistics.staff' => ['Offline Orders · Sales Associate Allocation', '线下订单 · 客服分摊'],
        'business.invoice' => ['Invoice Orders', 'Invoice订单'],
        'business.invoice.list' => ['Search', '搜索'],
        'business.invoice.create' => ['Register Invoice', '录入 Invoice'],
        'business.invoice.update' => ['Edit', '编辑'],
        'business.invoice.delete' => ['Delete', '删除'],
        'business.invoice.ocr' => ['Recognize Screenshot Text', '识别截图文字'],
        'business.invoice.logs' => ['Operation Logs', '操作记录'],
        'business.invoice.export' => ['Export All Logs', '导出全部日志'],
        'business.sa_sales' => ['SA Sales', 'SA 销售统计'],
        'business.sa_sales.list' => ['SA Sales', 'SA 销售统计'],
        'business.sa_sales.export' => ['Export CSV', '导出 CSV'],
        'business.procurement' => ['Purchasing Workbench', '采购工作台'],
        'business.procurement.list' => ['Search', '搜索'],
        'business.procurement.statistics' => ['Valid Orders', '有效订单'],
        'business.procurement.create' => ['Create Purchase Task', '创建采购任务'],
        'business.procurement.update' => ['Edit', '编辑'],
        'business.procurement.delete' => ['Remove', '移除'],
        'business.procurement.logs' => ['Operation Logs', '操作记录'],
        'business.procurement.export' => ['Export Orders / Export All Logs', '导出订单 / 导出全部日志'],
        'business.procurement.logistics' => ['Refresh tracking', '刷新物流'],
        'business.warehouse' => ['Warehouse Workbench', '仓库工作台'],
        'business.warehouse.list' => ['Warehouse Orders', '仓库订单'],
        'business.warehouse.update' => ['Inspect / Ship', '质检 / 发货'],
        'business.influencer' => ['Influencer Sales', '达人销量'],
        'business.influencer.list' => ['Influencer & Website Directory', '达人与网站对应关系大全'],
        'business.influencer.statistics' => ['Influencer Sales', '达人销量'],
        'business.influencer.create' => ['Add Website', '给网红添加网站'],
        'business.influencer.export' => ['Download Mapping CSV', '下载网红网站对应表'],
        'business.paypal' => ['PayPal Balance Monitor', 'Paypal余额监控'],
        'business.paypal.list' => ['PayPal Accounts', 'PayPal 账号'],
        'business.paypal.orders' => ['Selected Result', '筛选结果'],
        'business.paypal.orders_export' => ['Download Orders', '下载订单'],
        'business.paypal.withdrawals' => ['Withdrawal Record', '提款记录'],
        'business.paypal.statistics' => ['Withdrawals', '提款金额'],
        'business.paypal.logs' => ['Download Change Log', '下载修改日志'],
        'business.paypal.create' => ['Add Account', '新增账号'],
        'business.paypal.balance' => ['Correction', '纠正金额'],
        'business.paypal.review' => ['Number of Reviews', '审核次数'],
        'business.paypal.withdrawal' => ['Withdrawl', '操作提款'],
        'business.paypal.export' => ['Export All Results', '导出全部查询结果'],
        'business.operations' => ['Online Spreadsheet Hub', '在线表格中心'],
        'business.operations.list' => ['Online Spreadsheet Hub', '在线表格中心'],
        'system.user' => ['User Management', '用户管理'],
        'system.user.create' => ['New User', '新增用户'],
        'system.user.update' => ['Edit', '编辑'],
        'system.user.delete' => ['Delete', '删除'],
        'system.user.assign_role' => ['Assign Roles', '分配角色'],
        'system.role' => ['Role Management', '角色管理'],
        'system.role.create' => ['New Role', '新增角色'],
        'system.role.update' => ['Edit', '编辑'],
        'system.role.delete' => ['Delete', '删除'],
        'system.role.assign_permission' => ['Permissions', '权限点'],
        'system.permission' => ['Permission Management', '权限管理'],
        'system.permission.create' => ['New Permission', '新增权限'],
        'system.permission.update' => ['Edit', '编辑'],
        'system.permission.delete' => ['Delete', '删除'],
    ];

    /**
     * 仅在现有权限名称发生变化时更新，重复执行不改变更新时间。
     *
     * @return void
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::NAMES as $code => [$name, $nameZh]) {
                $permission = DB::table('permissions')->where('code', $code)->whereNull('deleted_at')->first();
                if (!$permission || ($permission->name === $name && $permission->name_zh === $nameZh)) {
                    continue;
                }

                DB::table('permissions')->where('id', $permission->id)->update([
                    'name' => $name,
                    'name_zh' => $nameZh,
                    'updated_at' => now(),
                ]);
            }
        });
    }
}
