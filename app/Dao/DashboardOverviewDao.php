<?php

namespace App\Dao;

use App\Models\ExchangeRate;
use App\Models\InvoiceOrder;
use App\Models\OnlineSpreadsheet;
use App\Models\Order;
use App\Models\PaypalAccount;
use App\Models\PaypalWithdrawal;
use Carbon\CarbonImmutable;

/**
 * 首页概览数据访问：封装模型查询与持久化操作。
 */
class DashboardOverviewDao
{
    /**
     * 读取指定日期前最近的各币种汇率。
     */
    public function exchangeRates(string $date): array
    {
        return ExchangeRate::where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get()
            ->unique('currency')
            ->map(fn ($rate) => [
                'currency' => $rate->currency,
                'rateToUsd' => (float) $rate->rate_to_usd,
                'effectiveDate' => $rate->effective_date,
            ])
            ->values()
            ->all();
    }

    /**
     * 读取账户及历史停用状态。
     */
    public function paypalAccounts(): array
    {
        // Historical withdrawals remain visible after an account is disabled.
        return PaypalAccount::withTrashed()
            ->get([
                'id',
                'email',
                'account_name',
                'active',
                'deleted_at',
            ])
            ->toArray();
    }

    /**
     * 关联提现记录。
     */
    public function withdrawals(array $range): array
    {
        return PaypalWithdrawal::whereBetween('withdrawn_at', [$range['startDate'], $range['endDate']])
            ->selectRaw('account_id, sum(amount) as amount')
            ->groupBy('account_id')
            ->pluck('amount', 'account_id')
            ->all();
    }

    /**
     * 读取启用的在线协作表格。
     */
    public function spreadsheets(): array
    {
        return OnlineSpreadsheet::where('active', true)
            ->orderBy('sort')
            ->get([
                'id',
                'department',
                'provider',
                'title_zh',
                'title_en',
                'description_zh',
                'description_en',
                'url',
            ])
            ->toArray();
    }

    /**
     * 查询数据库中的业务日期覆盖和更新时间。
     */
    public function dataStatus(): array
    {
        $orders = Order::selectRaw('count(*) as count, min(order_time) as first_at, max(order_time) as last_at, max(updated_at) as updated_at')
            ->first();
        $invoices = InvoiceOrder::selectRaw('count(*) as count, min(coalesce(order_date,invoice_date)) as first_date, max(coalesce(order_date,invoice_date)) as last_date, max(updated_at) as updated_at')
            ->first();
        $day = fn ($date) => $date ? CarbonImmutable::parse($date)
            ->setTimezone('Asia/Shanghai')
            ->toDateString() : null;
        $starts = array_filter([$day($orders->first_at), $invoices->first_date]);
        $ends = array_filter([$day($orders->last_at), $invoices->last_date]);
        $updates = array_filter([$orders->updated_at?->toIso8601String(), $invoices->updated_at?->toIso8601String()]);

        return [
            'firstDate' => $starts ? min($starts) : null,
            'dataThrough' => $ends ? max($ends) : null,
            'lastDatabaseUpdate' => $updates ? max($updates) : null,
            'orderRecords' => (int) $orders->count,
            'invoiceRecords' => (int) $invoices->count,
            'collectorState' => null,
            'backfillProgress' => null,
        ];
    }
}
