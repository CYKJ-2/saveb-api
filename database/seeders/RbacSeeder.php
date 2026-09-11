<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 可随 Git 分发的基础 RBAC。任何新环境仅凭源码和 .env 即可初始化。
 * 菜单名称、路径、排序来自当前基础权限配置；不包含个人账号、密码或登录 Token。
 * 通过 code 关联，不依赖本地数据库 ID；重跑保留已有角色授权、密码和账号状态。
 */
class RbacSeeder extends Seeder
{
    /** 基础菜单及操作权限；新增功能时直接维护此目录并验证 API 权限覆盖。 */
    private const PERMISSIONS = [
        'dashboard' => ['name' => 'Sales Live Dashboard', 'name_zh' => '销售实况', 'type' => 'menu', 'path' => '/dashboard', 'component' => 'Layout', 'icon' => 'HomeOutlined', 'action' => 'custom', 'resource' => null, 'level' => 1, 'sort' => 10, 'is_menu_visible' => true, 'hidden' => false, 'parent_code' => null],
        'dashboard.order_management' => ['name' => 'Order Management', 'name_zh' => '订单管理', 'type' => 'menu', 'path' => '/workbench/order-management', 'component' => 'workbench/order-management/index', 'icon' => 'ShoppingCartOutlined', 'action' => 'custom', 'resource' => null, 'level' => 2, 'sort' => 1, 'is_menu_visible' => true, 'hidden' => false, 'parent_code' => 'business'],
        'system.order.list' => ['name' => 'Order Search', 'name_zh' => '订单查询', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'list', 'resource' => 'orders', 'level' => 3, 'sort' => 10, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.order_management'],
        'system.order.export' => ['name' => 'Export All Results', 'name_zh' => '导出全部查询结果', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'export', 'resource' => 'orders', 'level' => 3, 'sort' => 50, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.order_management'],
        'system.order.testing' => ['name' => 'Test Orders', 'name_zh' => '测试订单', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'orders', 'level' => 3, 'sort' => 60, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.order_management'],
        'system.order.update' => ['name' => 'Edit', 'name_zh' => '编辑', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'update', 'resource' => 'orders', 'level' => 3, 'sort' => 30, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.order_management'],
        'system.order.create' => ['name' => 'Create Order', 'name_zh' => '创建订单', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'create', 'resource' => 'orders', 'level' => 3, 'sort' => 20, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.order_management'],
        'system.order.delete' => ['name' => 'Delete Order', 'name_zh' => '删除订单', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'delete', 'resource' => 'orders', 'level' => 3, 'sort' => 40, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.order_management'],
        'system.order.statistics.overview' => ['name' => 'Orders / Items / Sales', 'name_zh' => '成交订单 / 成交件数 / 销售额', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'orders', 'level' => 3, 'sort' => 70, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.order_management'],
        'system.order.statistics.currencies' => ['name' => 'Sales by Currency', 'name_zh' => '币种销售汇总', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'orders', 'level' => 3, 'sort' => 80, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.order_management'],
        'system.order.statistics.sales-trend' => ['name' => 'Sales Trend', 'name_zh' => '销售趋势', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'orders', 'level' => 3, 'sort' => 90, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.order_management'],
        'system.order.statistics.categories' => ['name' => 'Sales Category', 'name_zh' => '销售分类', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'orders', 'level' => 3, 'sort' => 100, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.order_management'],
        'system.order.statistics.influencers' => ['name' => 'Influencer Ranking', 'name_zh' => '达人排行', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'orders', 'level' => 3, 'sort' => 110, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.order_management'],
        'system.order.statistics.staff' => ['name' => 'Offline Orders · Sales Associate Allocation', 'name_zh' => '线下订单 · 客服分摊', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'orders', 'level' => 3, 'sort' => 120, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.order_management'],
        'business' => ['name' => 'Business', 'name_zh' => '业务管理', 'type' => 'menu', 'path' => '/workbench', 'component' => 'Layout', 'icon' => 'ShoppingCartOutlined', 'action' => 'custom', 'resource' => null, 'level' => 1, 'sort' => 20, 'is_menu_visible' => true, 'hidden' => false, 'parent_code' => null],
        'business.invoice' => ['name' => 'Invoice Orders', 'name_zh' => 'Invoice订单', 'type' => 'menu', 'path' => '/workbench/invoice', 'component' => 'workbench/invoice/index', 'icon' => null, 'action' => 'custom', 'resource' => null, 'level' => 2, 'sort' => 10, 'is_menu_visible' => true, 'hidden' => false, 'parent_code' => 'business'],
        'business.invoice.list' => ['name' => 'Search', 'name_zh' => '搜索', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'list', 'resource' => 'business.invoice', 'level' => 3, 'sort' => 10, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.invoice'],
        'business.invoice.create' => ['name' => 'Register Invoice', 'name_zh' => '录入 Invoice', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'create', 'resource' => 'business.invoice', 'level' => 3, 'sort' => 20, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.invoice'],
        'business.invoice.update' => ['name' => 'Edit', 'name_zh' => '编辑', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'update', 'resource' => 'business.invoice', 'level' => 3, 'sort' => 30, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.invoice'],
        'business.invoice.delete' => ['name' => 'Delete', 'name_zh' => '删除', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'delete', 'resource' => 'business.invoice', 'level' => 3, 'sort' => 40, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.invoice'],
        'business.invoice.ocr' => ['name' => 'Recognize Screenshot Text', 'name_zh' => '识别截图文字', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'business.invoice', 'level' => 3, 'sort' => 50, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.invoice'],
        'business.invoice.logs' => ['name' => 'Operation Logs', 'name_zh' => '操作记录', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'business.invoice', 'level' => 3, 'sort' => 60, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.invoice'],
        'business.invoice.export' => ['name' => 'Export All Logs', 'name_zh' => '导出全部日志', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'export', 'resource' => 'business.invoice', 'level' => 3, 'sort' => 70, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.invoice'],
        'business.sa_sales' => ['name' => 'SA Sales', 'name_zh' => 'SA 销售统计', 'type' => 'menu', 'path' => '/workbench/sa-sales', 'component' => 'workbench/sa-sales/index', 'icon' => null, 'action' => 'custom', 'resource' => null, 'level' => 2, 'sort' => 20, 'is_menu_visible' => true, 'hidden' => false, 'parent_code' => 'business'],
        'business.sa_sales.list' => ['name' => 'SA Sales', 'name_zh' => 'SA 销售统计', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'list', 'resource' => 'business.sa_sales', 'level' => 3, 'sort' => 10, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.sa_sales'],
        'business.sa_sales.export' => ['name' => 'Export CSV', 'name_zh' => '导出 CSV', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'export', 'resource' => 'business.sa_sales', 'level' => 3, 'sort' => 20, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.sa_sales'],
        'business.procurement' => ['name' => 'Purchasing', 'name_zh' => '采购部', 'type' => 'menu', 'path' => '/workbench/procurement', 'component' => 'workbench/procurement/index', 'icon' => null, 'action' => 'custom', 'resource' => null, 'level' => 2, 'sort' => 30, 'is_menu_visible' => true, 'hidden' => false, 'parent_code' => 'business'],
        'business.procurement.list' => ['name' => 'Search', 'name_zh' => '搜索', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'list', 'resource' => 'business.procurement', 'level' => 3, 'sort' => 10, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.procurement'],
        'business.procurement.statistics' => ['name' => 'Valid Orders', 'name_zh' => '有效订单', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'business.procurement', 'level' => 3, 'sort' => 20, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.procurement'],
        'business.procurement.create' => ['name' => 'Create Purchase Task', 'name_zh' => '创建采购任务', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'create', 'resource' => 'business.procurement', 'level' => 3, 'sort' => 30, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.procurement'],
        'business.procurement.update' => ['name' => 'Edit', 'name_zh' => '编辑', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'update', 'resource' => 'business.procurement', 'level' => 3, 'sort' => 40, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.procurement'],
        'business.procurement.delete' => ['name' => 'Remove', 'name_zh' => '移除', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'delete', 'resource' => 'business.procurement', 'level' => 3, 'sort' => 50, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.procurement'],
        'business.procurement.logs' => ['name' => 'Operation Logs', 'name_zh' => '操作记录', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'business.procurement', 'level' => 3, 'sort' => 60, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.procurement'],
        'business.procurement.export' => ['name' => 'Export Orders / Export All Logs', 'name_zh' => '导出订单 / 导出全部日志', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'export', 'resource' => 'business.procurement', 'level' => 3, 'sort' => 70, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.procurement'],
        'business.warehouse' => ['name' => 'Warehouse', 'name_zh' => '仓储部', 'type' => 'menu', 'path' => '/workbench/warehouse', 'component' => 'workbench/warehouse/index', 'icon' => null, 'action' => 'custom', 'resource' => null, 'level' => 2, 'sort' => 40, 'is_menu_visible' => true, 'hidden' => false, 'parent_code' => 'business'],
        'business.warehouse.list' => ['name' => 'Warehouse Orders', 'name_zh' => '仓库订单', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'list', 'resource' => 'business.warehouse', 'level' => 3, 'sort' => 10, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.warehouse'],
        'business.warehouse.update' => ['name' => 'Inspect / Ship', 'name_zh' => '质检 / 发货', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'update', 'resource' => 'business.warehouse', 'level' => 3, 'sort' => 20, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.warehouse'],
        'business.influencer' => ['name' => 'Influencer', 'name_zh' => '达人部', 'type' => 'menu', 'path' => '/workbench/influencer', 'component' => 'workbench/influencer/index', 'icon' => null, 'action' => 'custom', 'resource' => null, 'level' => 2, 'sort' => 50, 'is_menu_visible' => true, 'hidden' => false, 'parent_code' => 'business'],
        'business.influencer.list' => ['name' => 'Influencer & Website Directory', 'name_zh' => '达人与网站对应关系大全', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'list', 'resource' => 'business.influencer', 'level' => 3, 'sort' => 10, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.influencer'],
        'business.influencer.statistics' => ['name' => 'Influencer Sales', 'name_zh' => '达人销量', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'business.influencer', 'level' => 3, 'sort' => 20, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.influencer'],
        'business.influencer.create' => ['name' => 'Add Website', 'name_zh' => '给网红添加网站', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'create', 'resource' => 'business.influencer', 'level' => 3, 'sort' => 30, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.influencer'],
        'business.influencer.export' => ['name' => 'Download Mapping CSV', 'name_zh' => '下载网红网站对应表', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'export', 'resource' => 'business.influencer', 'level' => 3, 'sort' => 40, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.influencer'],
        'business.paypal' => ['name' => 'Balance Monitor', 'name_zh' => '余额监控', 'type' => 'menu', 'path' => '/workbench/paypal', 'component' => 'workbench/paypal/index', 'icon' => null, 'action' => 'custom', 'resource' => null, 'level' => 2, 'sort' => 60, 'is_menu_visible' => true, 'hidden' => false, 'parent_code' => 'business'],
        'business.paypal.list' => ['name' => 'PayPal Accounts', 'name_zh' => 'PayPal 账号', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'list', 'resource' => 'business.paypal', 'level' => 3, 'sort' => 10, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.paypal'],
        'business.paypal.orders' => ['name' => 'Selected Result', 'name_zh' => '筛选结果', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'business.paypal', 'level' => 3, 'sort' => 20, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.paypal'],
        'business.paypal.create' => ['name' => 'Add Account', 'name_zh' => '新增账号', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'create', 'resource' => 'business.paypal', 'level' => 3, 'sort' => 30, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.paypal'],
        'business.paypal.balance' => ['name' => 'Correction', 'name_zh' => '纠正金额', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'business.paypal', 'level' => 3, 'sort' => 40, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.paypal'],
        'business.paypal.review' => ['name' => 'Number of Reviews', 'name_zh' => '审核次数', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'business.paypal', 'level' => 3, 'sort' => 50, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.paypal'],
        'business.paypal.withdrawal' => ['name' => 'Withdrawl', 'name_zh' => '操作提款', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'business.paypal', 'level' => 3, 'sort' => 60, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.paypal'],
        'business.paypal.export' => ['name' => 'Export All Results', 'name_zh' => '导出全部查询结果', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'export', 'resource' => 'business.paypal', 'level' => 3, 'sort' => 70, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.paypal'],
        'business.operations' => ['name' => 'Work Patrol', 'name_zh' => '全域工作巡检', 'type' => 'menu', 'path' => '/workbench/operations', 'component' => 'workbench/operations/index', 'icon' => null, 'action' => 'custom', 'resource' => null, 'level' => 2, 'sort' => 70, 'is_menu_visible' => true, 'hidden' => false, 'parent_code' => 'business'],
        'business.operations.list' => ['name' => 'Online Spreadsheet Hub', 'name_zh' => '在线表格中心', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'list', 'resource' => 'business.operations', 'level' => 3, 'sort' => 10, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.operations'],
        'dashboard.overview' => ['name' => 'Sales Live Dashboard', 'name_zh' => '销售实况', 'type' => 'menu', 'path' => '/dashboard/overview', 'component' => 'dashboard/index', 'icon' => 'HomeOutlined', 'action' => 'custom', 'resource' => null, 'level' => 2, 'sort' => 10, 'is_menu_visible' => true, 'hidden' => false, 'parent_code' => 'dashboard'],
        'dashboard.overview.overview' => ['name' => 'Key Metrics / Payment Status', 'name_zh' => '核心指标 / 付款状态', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'dashboard.overview', 'level' => 3, 'sort' => 10, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.overview'],
        'dashboard.overview.sales_trend' => ['name' => 'Sales Trend', 'name_zh' => '销售趋势', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'dashboard.overview', 'level' => 3, 'sort' => 20, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.overview'],
        'dashboard.overview.categories' => ['name' => 'Order Categories', 'name_zh' => '订单分类', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'dashboard.overview', 'level' => 3, 'sort' => 30, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.overview'],
        'dashboard.overview.influencers' => ['name' => 'Influencer Sales Ranking', 'name_zh' => '达人销售排行', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'dashboard.overview', 'level' => 3, 'sort' => 40, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.overview'],
        'dashboard.overview.staff' => ['name' => 'Offline Sales Associate Performance', 'name_zh' => '线下客服业绩', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'dashboard.overview', 'level' => 3, 'sort' => 50, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.overview'],
        'dashboard.overview.recent_orders' => ['name' => 'Recent Orders', 'name_zh' => '最近订单', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'dashboard.overview', 'level' => 3, 'sort' => 60, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.overview'],
        'dashboard.overview.currencies' => ['name' => 'Currency Statistics', 'name_zh' => '币种统计', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'dashboard.overview', 'level' => 3, 'sort' => 70, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.overview'],
        'dashboard.overview.paypal' => ['name' => 'PayPal Income and Expenses', 'name_zh' => 'PayPal 收支', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'dashboard.overview', 'level' => 3, 'sort' => 80, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.overview'],
        'dashboard.overview.exchange_rates' => ['name' => 'Exchange Rates', 'name_zh' => '汇率参考', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'dashboard.overview', 'level' => 3, 'sort' => 90, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.overview'],
        'dashboard.overview.system_status' => ['name' => 'Data Coverage Status', 'name_zh' => '数据覆盖状态', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'dashboard.overview', 'level' => 3, 'sort' => 100, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.overview'],
        'dashboard.overview.spreadsheets' => ['name' => 'Online Spreadsheets', 'name_zh' => '在线表格', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'dashboard.overview', 'level' => 3, 'sort' => 110, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.overview'],
        'system' => ['name' => 'System', 'name_zh' => '系统管理', 'type' => 'menu', 'path' => '/system', 'component' => 'Layout', 'icon' => 'SettingOutlined', 'action' => 'custom', 'resource' => null, 'level' => 1, 'sort' => 100, 'is_menu_visible' => true, 'hidden' => false, 'parent_code' => null],
        'system.user' => ['name' => 'User Management', 'name_zh' => '用户管理', 'type' => 'menu', 'path' => '/system/users', 'component' => 'system/UserList', 'icon' => null, 'action' => 'custom', 'resource' => null, 'level' => 2, 'sort' => 10, 'is_menu_visible' => true, 'hidden' => false, 'parent_code' => 'system'],
        'system.user.list' => ['name' => 'View Users', 'name_zh' => '查看用户', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'list', 'resource' => 'system.user', 'level' => 3, 'sort' => 10, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'system.user'],
        'system.user.create' => ['name' => 'New User', 'name_zh' => '新增用户', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'create', 'resource' => 'system.user', 'level' => 3, 'sort' => 20, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'system.user'],
        'system.user.update' => ['name' => 'Edit', 'name_zh' => '编辑', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'update', 'resource' => 'system.user', 'level' => 3, 'sort' => 30, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'system.user'],
        'system.user.delete' => ['name' => 'Delete', 'name_zh' => '删除', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'delete', 'resource' => 'system.user', 'level' => 3, 'sort' => 40, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'system.user'],
        'system.user.assign_role' => ['name' => 'Assign Roles', 'name_zh' => '分配角色', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'system.user', 'level' => 3, 'sort' => 50, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'system.user'],
        'system.role' => ['name' => 'Role Management', 'name_zh' => '角色管理', 'type' => 'menu', 'path' => '/system/roles', 'component' => 'system/RoleList', 'icon' => null, 'action' => 'custom', 'resource' => null, 'level' => 2, 'sort' => 20, 'is_menu_visible' => true, 'hidden' => false, 'parent_code' => 'system'],
        'system.role.list' => ['name' => 'View Roles', 'name_zh' => '查看角色', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'list', 'resource' => 'system.role', 'level' => 3, 'sort' => 10, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'system.role'],
        'system.role.create' => ['name' => 'New Role', 'name_zh' => '新增角色', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'create', 'resource' => 'system.role', 'level' => 3, 'sort' => 20, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'system.role'],
        'system.role.update' => ['name' => 'Edit', 'name_zh' => '编辑', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'update', 'resource' => 'system.role', 'level' => 3, 'sort' => 30, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'system.role'],
        'system.role.delete' => ['name' => 'Delete', 'name_zh' => '删除', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'delete', 'resource' => 'system.role', 'level' => 3, 'sort' => 40, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'system.role'],
        'system.role.assign_permission' => ['name' => 'Permissions', 'name_zh' => '权限点', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => 'system.role', 'level' => 3, 'sort' => 50, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'system.role'],
        'system.permission' => ['name' => 'Permission Management', 'name_zh' => '权限管理', 'type' => 'menu', 'path' => '/system/permissions', 'component' => 'system/PermissionList', 'icon' => null, 'action' => 'custom', 'resource' => null, 'level' => 2, 'sort' => 30, 'is_menu_visible' => true, 'hidden' => false, 'parent_code' => 'system'],
        'system.permission.read' => ['name' => 'View Permissions', 'name_zh' => '查看权限', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'list', 'resource' => 'system.permission', 'level' => 3, 'sort' => 10, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'system.permission'],
        'system.permission.create' => ['name' => 'New Permission', 'name_zh' => '新增权限', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'create', 'resource' => 'system.permission', 'level' => 3, 'sort' => 20, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'system.permission'],
        'system.permission.update' => ['name' => 'Edit', 'name_zh' => '编辑', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'update', 'resource' => 'system.permission', 'level' => 3, 'sort' => 30, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'system.permission'],
        'system.permission.delete' => ['name' => 'Delete', 'name_zh' => '删除', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'delete', 'resource' => 'system.permission', 'level' => 3, 'sort' => 40, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'system.permission'],
        'dashboard.overview.collector_status' => ['name' => 'Collector monitoring', 'name_zh' => '采集运行监控', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => null, 'level' => 3, 'sort' => 0, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.overview'],
        'dashboard.overview.collector_trigger' => ['name' => 'Collect today’s data', 'name_zh' => '采集当天数据', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => null, 'level' => 3, 'sort' => 0, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.overview'],
        'dashboard.collector' => ['name' => 'Collection management', 'name_zh' => '采集管理', 'type' => 'menu', 'path' => '/system/collector', 'component' => 'dashboard/collector/index', 'icon' => 'SyncOutlined', 'action' => 'custom', 'resource' => null, 'level' => 2, 'sort' => 60, 'is_menu_visible' => true, 'hidden' => false, 'parent_code' => 'system'],
        'dashboard.collector.settings' => ['name' => 'Save interval', 'name_zh' => '保存间隔', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => null, 'level' => 3, 'sort' => 0, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.collector'],
        'dashboard.collector.collect' => ['name' => 'Range update / Missing dates', 'name_zh' => '范围更新 / 仅补缺', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => null, 'level' => 3, 'sort' => 0, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.collector'],
        'dashboard.collector.reprocess' => ['name' => 'Archive recalculation preview', 'name_zh' => '归档重算预览（不入库）', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => null, 'level' => 3, 'sort' => 0, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'dashboard.collector'],
        'business.procurement.logistics' => ['name' => 'Refresh tracking', 'name_zh' => '刷新物流', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => null, 'level' => 3, 'sort' => 0, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.procurement'],
        'business.paypal.orders_export' => ['name' => 'Download Orders', 'name_zh' => '下载订单', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => null, 'level' => 3, 'sort' => 0, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.paypal'],
        'business.paypal.withdrawals' => ['name' => 'Withdrawal Record', 'name_zh' => '提款记录', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => null, 'level' => 3, 'sort' => 0, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.paypal'],
        'business.paypal.statistics' => ['name' => 'Withdrawals', 'name_zh' => '提款金额', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => null, 'level' => 3, 'sort' => 0, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.paypal'],
        'business.paypal.logs' => ['name' => 'Download Change Log', 'name_zh' => '下载修改日志', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => null, 'level' => 3, 'sort' => 0, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.paypal'],
        'inspection' => ['name' => 'Inspection Photo System', 'name_zh' => '验货系统', 'type' => 'menu', 'path' => 'https://www.saveb-photos.com/admin', 'component' => null, 'icon' => 'Camera', 'action' => 'custom', 'resource' => null, 'level' => 1, 'sort' => 90, 'is_menu_visible' => true, 'hidden' => false, 'parent_code' => null],
        'business.analysis' => ['name' => 'Analysis', 'name_zh' => 'Analysis', 'type' => 'menu', 'path' => '/workbench/analysis', 'component' => 'workbench/analysis/index', 'icon' => 'DataAnalysis', 'action' => 'custom', 'resource' => null, 'level' => 2, 'sort' => 0, 'is_menu_visible' => true, 'hidden' => false, 'parent_code' => 'business'],
        'business.analysis.list' => ['name' => 'View analysis', 'name_zh' => '查看分析', 'type' => 'action', 'path' => '', 'component' => null, 'icon' => null, 'action' => 'list', 'resource' => null, 'level' => 3, 'sort' => 0, 'is_menu_visible' => false, 'hidden' => true, 'parent_code' => 'business.analysis'],
        'business.analysis.import' => ['name' => 'Import workbooks', 'name_zh' => '采集表格', 'type' => 'action', 'path' => '', 'component' => null, 'icon' => null, 'action' => 'create', 'resource' => null, 'level' => 3, 'sort' => 0, 'is_menu_visible' => false, 'hidden' => true, 'parent_code' => 'business.analysis'],
        'business.analysis.export' => ['name' => 'Export analysis rows', 'name_zh' => '导出分析明细', 'type' => 'action', 'path' => '', 'component' => null, 'icon' => null, 'action' => 'export', 'resource' => null, 'level' => 3, 'sort' => 0, 'is_menu_visible' => false, 'hidden' => true, 'parent_code' => 'business.analysis'],
        'business.sa_sales.personal' => ['name' => 'View Personal Performance', 'name_zh' => '查看个人业绩详情', 'type' => 'action', 'path' => null, 'component' => null, 'icon' => null, 'action' => 'custom', 'resource' => null, 'level' => 3, 'sort' => 0, 'is_menu_visible' => false, 'hidden' => false, 'parent_code' => 'business.sa_sales'],
    ];

    /** 初始化基础角色、完整权限目录和默认管理员。 */
    public function run(): void
    {
        DB::transaction(function (): void {
            $roles = $this->seedRoles();
            $ids = [];
            foreach (array_keys(self::PERMISSIONS) as $code) {
                $this->seedPermission($code, $ids);
            }
            // 旧入口不再展示，保留原记录及关联，以免已有授权发生级联删除。
            DB::table('permissions')->whereIn('code', ['orders', 'orders.list', 'dashboard.view'])->update([
                'status' => 0, 'hidden' => true, 'is_menu_visible' => false, 'updated_at' => now(),
            ]);
            $active = DB::table('permissions')->where('status', 1)->whereNull('deleted_at');
            $this->grantPermissions($roles['super_admin']['id'], (clone $active)->pluck('id')->all());
            // 只读角色首次仅授予首页查看权限，不复制本地对个别角色追加的写入授权。
            if ($roles['viewer']['created']) {
                $codes = ['dashboard', 'dashboard.overview'];
                foreach (self::PERMISSIONS as $code => $permission) {
                    if ($permission['parent_code'] === 'dashboard.overview' && $code !== 'dashboard.overview.collector_trigger') {
                        $codes[] = $code;
                    }
                }
                $this->grantPermissions($roles['viewer']['id'], (clone $active)->whereIn('code', $codes)->pluck('id')->all());
            }
            $this->ensureSuperAdmin($roles['super_admin']['id']);
        });
        $this->command?->info('基础 RBAC 已就绪；已有账号密码、状态和普通角色授权保持不变。');
    }

    /** 先按 code 创建父节点，再写子节点，避免依赖目录顺序或固定 ID。 */
    private function seedPermission(string $code, array &$ids): int
    {
        if (isset($ids[$code])) {
            return $ids[$code];
        }
        $attributes = self::PERMISSIONS[$code];
        $parentCode = $attributes['parent_code'];
        unset($attributes['parent_code']);
        $attributes['parent_id'] = $parentCode === null ? 0 : $this->seedPermission($parentCode, $ids);
        $existingId = DB::table('permissions')->where('code', $code)->value('id');
        $attributes['updated_at'] = now();
        if ($existingId !== null) {
            // 不改变 status/deleted_at，不复活已经人工停用或删除的权限。
            DB::table('permissions')->where('id', $existingId)->update($attributes);

            return $ids[$code] = (int) $existingId;
        }

        return $ids[$code] = DB::table('permissions')->insertGetId($attributes + [
            'code' => $code, 'status' => 1, 'created_at' => now(),
        ]);
    }

    /** 以 code 定位内置角色，避免覆盖同 ID 的自定义角色。 */
    private function seedRoles(): array
    {
        $roles = [];
        foreach ([
            'super_admin' => ['Super Administrator', '超级管理员', 0],
            'admin' => ['Administrator', '管理员', 10],
            'viewer' => ['Read-only Viewer', '只读用户', 90],
        ] as $code => [$name, $nameZh, $sort]) {
            $existing = DB::table('roles')->where('code', $code)->first();
            $roles[$code] = [
                'id' => $existing?->id ?? DB::table('roles')->insertGetId([
                    'code' => $code,
                    'name' => $name,
                    'name_zh' => $nameZh,
                    'status' => 1,
                    'is_system' => true,
                    'sort' => $sort,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
                'created' => $existing === null,
            ];
        }

        return $roles;
    }

    /** 仅补充缺失的授权关联，不清空服务器已调整的授权。 */
    private function grantPermissions(int $roleId, array $permissionIds): void
    {
        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId, 'permission_id' => $permissionId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** 仅创建缺失账号，重跑不提升已有同名用户权限或重置密码。 */
    private function ensureSuperAdmin(int $roleId): void
    {
        $username = 'super_admin';
        if (DB::table('users')->whereRaw('lower(username) = ?', [$username])->exists()) {
            return;
        }

        $userId = DB::table('users')->insertGetId([
            'username' => $username,
            'password_hash' => Hash::make('123456'),
            'display_name' => 'Super Administrator',
            'role_id' => $roleId,
            'active' => true,
            'must_change_password' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->command?->info('已创建初始管理员 super_admin，首次登录需修改密码。');
    }
}
