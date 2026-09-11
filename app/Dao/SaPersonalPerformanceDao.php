<?php

namespace App\Dao;

use App\Models\InvoiceOrder;
use App\Models\Order;
use App\Models\User;

/** 个人业绩附加字段查询；只为当前页读取电话和收款信息，不加载完整快照。 */
class SaPersonalPerformanceDao
{
    /**
     * 查询用户的客服编码和显示名称，保留离职用户用于历史业绩展示。
     *
     * @return array<int, array{staff_code: ?string, display_name: ?string, username: string}> 用户名称映射，启用用户优先
     */
    public function employeeNames(): array
    {
        return User::withTrashed()->whereNotNull('staff_code')
            ->orderByDesc('active')->orderBy('id')
            ->get(['staff_code', 'display_name', 'username'])->toArray();
    }

    /**
     * 批量读取当前页普通订单的电话、渠道、支付方式。
     *
     * @param array<int, int|string> $orderIds 当前页普通订单数据库主键
     * @return array<int|string, array> 以订单 ID 为键的 phone、channel、paymentMethod 字段
     */
    public function orderContacts(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return Order::whereKey($orderIds)->select('id')->selectRaw(<<<'SQL'
            coalesce(nullif(raw->>'phoneNumber', ''), nullif(raw->>'customerPhone', ''),
                nullif(raw->>'phone', ''), raw->'billing'->>'phone', '') AS phone,
            coalesce(nullif(raw->>'platform', ''), nullif(raw->>'channel', ''), nullif(raw->>'sourcePlatform', ''),
                nullif(raw->>'sourceCategory', ''), nullif(raw->>'category', ''), nullif(raw->>'clientSite', ''), nullif(source_site, ''), '') AS channel,
            coalesce(nullif(raw->>'paymentMethod', ''), nullif(raw->>'paymentType', ''), nullif(raw->>'paymentChannel', ''), '') AS "paymentMethod"
            SQL)->get()->keyBy('id')->toArray();
    }

    /**
     * 批量读取当前页 Invoice 的客户电话。
     *
     * @param array<int, int|string> $invoiceIds 当前页 Invoice 数据库主键
     * @return array<int|string, string> 以 Invoice ID 为键的电话，缺失时为空字符串
     */
    public function invoicePhones(array $invoiceIds): array
    {
        return $invoiceIds === [] ? [] : InvoiceOrder::whereKey($invoiceIds)
            ->pluck('phone_number', 'id')->map(fn ($phone) => (string) $phone)->all();
    }
}
