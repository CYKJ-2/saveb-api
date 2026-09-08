<?php

namespace App\Common;

/** 导出中的状态沿用原页面展示名称，未知状态保留原值。 */
class ExportValue
{
    public static function classification(string $value, string $locale): string
    {
        $labels = [
            'official' => ['主站群组', 'Official Sites'], 'top_influencer' => ['头部网红', 'Top Influencers'],
            'mid_influencer' => ['腰部网红', 'Mid Influencers'], 'offline' => ['线下订单', 'Payment Link Orders'],
            'payment_link' => ['线下订单', 'Payment Link Orders'], 'invoice' => ['Invoice订单', 'Invoice Orders'],
            'unmatched' => ['未匹配', 'Unmatched'],
        ];

        return $labels[$value][$locale === 'en-US' ? 1 : 0] ?? $value;
    }

    public static function status(string $value, string $locale): string
    {
        $labels = [
            'pending_purchase' => ['待采购', 'Pending Purchase'],
            'supplier_shipping_pending' => ['待供应商发货', 'Waiting Supplier Shipment'],
            'warehouse_arrived' => ['已到仓', 'Arrived Warehouse'],
            'exchange_in_progress' => ['换货中', 'Exchanging'],
            'return_in_progress' => ['退货中', 'Returning'],
            'customer_confirm_pending' => ['待顾客确认', 'Waiting Customer Confirmation'],
            'shipped' => ['已发货', 'Shipped'], 'pending' => ['待查询', 'Pending'],
            'in_transit' => ['运输中', 'In Transit'], 'out_for_delivery' => ['派送中', 'Out for Delivery'],
            'delivered' => ['已送达', 'Delivered'], 'exception' => ['物流异常', 'Exception'],
            'expired' => ['已过期', 'Expired'], 'unknown' => ['尚未识别', 'Unknown'],
            'unregistered' => ['待注册', 'Waiting Registration'], 'info_received' => ['已收到信息', 'Information Received'],
            'available_for_pickup' => ['待取件', 'Available for Pickup'], 'failed_attempt' => ['投递失败', 'Failed Attempt'],
            'normal' => ['普通', 'Normal'], 'urgent' => ['紧急', 'Urgent'], 'high' => ['高', 'High'],
        ];

        return $labels[$value][$locale === 'en-US' ? 1 : 0] ?? $value;
    }
}
