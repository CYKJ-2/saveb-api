<?php

namespace App\Dao;

use App\Models\InvoiceStaffAllocation;
use App\Models\Order;
use App\Models\OrderStaffAllocation;
use App\Models\User;

/**
 * SA 销售绩效数据访问：封装模型查询与持久化操作。
 */
class SaSalesDao
{
    /**
     * 包含在职员工和历史订单参与人，保证协作员工也能作为筛选项。
     *
     * @return array 可供明细筛选的当前及历史员工编码列表
     */
    public function staffCodes(): array
    {
        $codes = User::where('active', 1)->pluck('staff_code')->all();
        $codes = array_merge(
            $codes,
            OrderStaffAllocation::distinct()->pluck('staff_code')->all(),
            InvoiceStaffAllocation::distinct()->pluck('staff_code')->all(),
        );
        foreach (Order::select('id', 'staff_code')->selectRaw("raw->'staffAllocations' as staff_allocations")->lazyById(500) as $order) {
            foreach (preg_split('/[,，\/]+/', $order->staff_code ?? '') as $code) {
                $codes[] = $code;
            }
            $allocations = json_decode($order->staff_allocations ?? '[]', true);
            foreach (is_array($allocations) ? $allocations : [] as $allocation) {
                $codes[] = $allocation['staffCode'] ?? $allocation['staff'] ?? '';
            }
        }
        $codes = array_values(array_unique(array_filter(array_map(
            fn ($code) => strtoupper(trim($code ?? '')),
            $codes,
        ))));
        sort($codes, SORT_STRING);

        return $codes;
    }

    /**
     * 仅读取统计需要的来源字段，避免加载 raw 中的截图等大字段。
     *
     * @param  array  $orderIds  需要补充来源信息的普通订单主键 ID 列表
     * @return array 以普通订单 ID 为键的渠道及付款方式轻量映射
     */
    public function reportSources(array $orderIds): array
    {
        if (!$orderIds) {
            return [];
        }

        return Order::whereIn('id', $orderIds)
            ->select('id')
            ->selectRaw("coalesce(nullif(raw->>'platform',''), nullif(raw->>'channel',''), nullif(raw->>'sourcePlatform',''), nullif(raw->>'sourceCategory',''), nullif(raw->>'category',''), nullif(raw->>'clientSite',''), nullif(source_site,''), 'Unknown') as channel")
            ->selectRaw("coalesce(nullif(raw->>'paymentMethod',''), nullif(raw->>'paymentType',''), nullif(raw->>'paymentChannel','')) as payment_method")
            ->get()
            ->mapWithKeys(fn ($order) => [$order->id => [
                'channel' => $order->channel,
                'paymentMethod' => $order->payment_method,
            ]])
            ->all();
    }

    /**
     * 读取可用业务日期范围。
     *
     * @return array 数据刷新时间 refreshedAt、最早日期 firstDate 和覆盖日期 dataThrough
     */
    public function bounds(): array
    {
        $row = Order::selectRaw('min(order_time) as first_date,max(order_time) as last_date,max(updated_at) as refreshed_at')->first();

        return [
            'refreshedAt' => $row->refreshed_at ? \Carbon\CarbonImmutable::parse($row->refreshed_at)->toIso8601String() : null,
            'firstDate' => $row->first_date ? \Carbon\CarbonImmutable::parse($row->first_date)
                ->setTimezone('Asia/Shanghai')
                ->toDateString() : null,
            'dataThrough' => $row->last_date ? \Carbon\CarbonImmutable::parse($row->last_date)
                ->setTimezone('Asia/Shanghai')
                ->toDateString() : null,
        ];
    }
}
