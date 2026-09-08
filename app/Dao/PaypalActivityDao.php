<?php

namespace App\Dao;

use App\Models\LegacyDashboardDay;
use App\Models\Order;
use App\Models\SystemState;

/** PayPal 收款数据源，与原服务的 canonical + legacy-days 查询范围一致。 */
class PaypalActivityDao
{
    public function orders(): iterable
    {
        return Order::whereNotNull('receiving_paypal')->where('receiving_paypal', '<>', '')
            ->select(['id', 'order_id', 'client_order_id', 'paypal_order_id', 'order_time', 'customer_name',
                'source_site', 'classification', 'receiving_paypal', 'amount_original', 'currency',
                'amount_usd', 'order_status', 'staff_code'])
            ->selectRaw("jsonb_build_object('createTime',raw->'createTime','orderId',raw->'orderId','clientOrderId',raw->'clientOrderId') as raw")
            ->orderByDesc('order_time')->cursor();
    }

    public function daysAfter(string $date): iterable
    {
        return LegacyDashboardDay::where('day', '>', $date)->orderByDesc('day')->cursor();
    }

    public function rates(): array
    {
        return array_merge(SystemState::find('legacy_dashboard_context')?->value['exchangeRates'] ?? [], ['USD' => 1]);
    }
}
