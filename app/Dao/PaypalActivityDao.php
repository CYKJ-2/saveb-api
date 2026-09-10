<?php

namespace App\Dao;

use App\Models\LegacyDashboardDay;
use App\Models\Order;
use App\Models\SystemState;
use Carbon\CarbonImmutable;

/** PayPal 收款数据源，与原服务的 canonical + legacy-days 查询范围一致。 */
class PaypalActivityDao
{
    /**
     * 分批读取有收款账户的来源订单；本查询不限制订单状态。
     *
     * @param  array<int, string>|null  $emails  规范化的收款邮箱；null 查询全部，空数组不读取订单
     * @return iterable 按需迭代的PayPal 收款记录，供逐条处理或导出
     */
    public function orders(?array $emails = null): iterable
    {
        return Order::whereNotNull('receiving_paypal')->where('receiving_paypal', '<>', '')
            ->when($emails !== null, fn ($query) => $query->whereIn(\Illuminate\Support\Facades\DB::raw("lower(btrim(receiving_paypal, E' \\t\\n\\r\\013'))"), $emails))
            ->select(['id', 'order_id', 'client_order_id', 'paypal_order_id', 'order_time', 'customer_name',
                'source_site', 'classification', 'receiving_paypal', 'amount_original', 'currency',
                'amount_usd', 'order_status', 'staff_code'])
            ->selectRaw("jsonb_build_object('createTime',raw->'createTime','orderId',raw->'orderId','clientOrderId',raw->'clientOrderId') as raw")
            ->orderByDesc('order_time')->cursor();
    }

    /**
     * 获取所有已入库收款订单的 UTC 覆盖日期，避免按账户筛选后回退到较早日快照。
     *
     * @return string 全局最新日期 Y-m-d；无订单时为 1970-01-01
     */
    public function throughDate(): string
    {
        $latest = Order::whereNotNull('receiving_paypal')->where('receiving_paypal', '<>', '')->max('order_time');

        return $latest ? CarbonImmutable::parse($latest)->utc()->toDateString() : '1970-01-01';
    }

    /**
     * 读取指定日期之后的来源日快照，不包含边界日期。
     *
     * @param  string  $date  业务日期，格式 Y-m-d
     * @return iterable 按需迭代的PayPal 收款记录，供逐条处理或导出
     */
    public function daysAfter(string $date): iterable
    {
        return LegacyDashboardDay::where('day', '>', $date)->orderByDesc('day')->cursor();
    }

    /**
     * 读取来源状态中的币种汇率，供历史快照换算美元金额。
     *
     * @return array 原平台币种汇率映射，USD 固定为 1
     */
    public function rates(): array
    {
        return array_merge(SystemState::find('legacy_dashboard_context')?->value['exchangeRates'] ?? [], ['USD' => 1]);
    }
}
