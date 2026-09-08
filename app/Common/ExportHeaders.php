<?php

namespace App\Common;

/** 原平台 CSV 英文表头及其中文名称，字段顺序由各功能单独定义。 */
class ExportHeaders
{
    private const CHINESE = [
        'Order Time' => '下单时间', 'Order ID' => '订单ID', 'PayPal Order ID' => 'PayPal订单ID',
        'Customer' => '客户', 'Customer Full Name' => '顾客全名', 'Website' => '网站',
        'Source Site' => '来源网站', 'Status' => '状态', 'Staff' => '客服', 'PayPal' => '收款PayPal',
        'Amount' => '金额', 'Currency' => '币种', 'Date' => '日期', 'Classification' => '归类',
        'Order Status' => '订单状态', 'Product Name' => '商品名称', 'Receiving PayPal' => '收款PayPal',
        'Product' => '商品', 'Supplier' => '供应商', 'Purchaser' => '采购员', 'Cost' => '采购成本',
        'Expected Arrival' => '预计到货', 'Carrier' => '物流公司', 'Tracking' => '物流单号',
        'Tracking Phone' => '物流电话', 'Delivery Status' => '物流状态', 'Purchase Status' => '采购状态',
        'Priority' => '优先级', 'Notes' => '备注', 'Influencer' => '达人', 'Confirmed' => '已确认',
        'Operation Time' => '操作时间', 'Operator' => '操作人', 'Action' => '操作', 'Entity' => '对象',
        'Record ID' => '记录ID', 'Order Number' => '订单号', 'Changed Fields' => '变更字段', 'Details' => '详情',
        'Email' => '邮箱', 'Account Name' => '账号名', 'Date Added' => '账号创建日期',
        'Balance USD' => '余额 USD', 'Total Received USD' => '累计收款 USD',
        'Total Withdrawn USD' => '累计提款 USD', 'Reviews' => '审核次数',
        'Time' => '时间', 'Field' => '字段', 'Previous Value' => '修改前', 'New Value' => '修改后',
        'Delta' => '变更量', 'Updated By' => '修改人', 'Withdrawal Amount' => '提款金额', 'Source' => '来源',
        'SA Sales Performance Report' => 'SA 销售绩效报表', 'Date Range' => '日期范围',
        'Data Through' => '数据截至', 'Refreshed At' => '刷新时间', 'Summary' => '汇总',
        'Orders' => '订单数', 'Refund Orders' => '退款订单数', 'Net Sales USD' => '净销售额 USD',
        'Total Commission USD' => '提成总额 USD', 'Average Order Value USD' => '客单价 USD',
        'Active Days' => '活跃天数', 'Daily Average USD' => '日均销售额 USD', 'Top Seller' => '最佳销售员',
        'Employee Ranking' => '员工排行榜', 'Invoice Employee Ranking' => 'Invoice 员工排行榜',
        'Rank' => '排名', 'Employee' => '员工', 'Commission USD' => '提成金额 USD',
        'Channel Summary' => '渠道统计', 'Channel' => '渠道', 'Sales USD' => '销售额 USD',
        'Refunds USD' => '退款额 USD', 'Positive Sales Share %' => '正向销售占比 %',
        'Daily Summary' => '每日统计', 'Order Detail' => '订单明细', 'Stable Identity' => '唯一标识',
        'Date Ordered' => '下单日期', 'Amount USD' => '金额 USD', 'Payment Method' => '收款方式',
        'Payment Account' => '收款账户', 'Refund' => '退款',
    ];

    public static function locale(): string
    {
        return request()->validate(['locale' => 'nullable|in:zh-CN,en-US'])['locale'] ?? 'zh-CN';
    }

    public static function translate(string $label, string $locale): string
    {
        return $locale === 'en-US' ? $label : (self::CHINESE[$label] ?? $label);
    }
}
