<?php

namespace App\Dao;

use App\Models\ExchangeRate;
use App\Models\InvoiceOrder;
use App\Models\InvoiceStaffAllocation;
use App\Models\Order;
use App\Models\OrderStaffAllocation;
use App\Models\OrderUserOverride;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** All database access for the dashboard, including legacy override reconciliation. */
class OrderManagementDao
{
    /**
     * 获取在职客服和历史订单参与人；只读编码，避免加载订单截图等大字段。
     *
     * @return array<int, string>
     */
    public function staffCodes(): array
    {
        $codes = User::where('active', 1)->pluck('staff_code')
            ->merge(Order::distinct()->pluck('staff_code'))
            ->merge(OrderUserOverride::distinct()->pluck('primary_staff_code'))
            ->merge(OrderStaffAllocation::distinct()->pluck('staff_code'))
            ->merge(InvoiceStaffAllocation::distinct()->pluck('staff_code'));
        $codes = $codes->merge(Order::selectRaw("DISTINCT raw->>'primaryStaffCode' AS code")->get()->pluck('code'));
        $allocationCodes = Order::query()
            ->crossJoin(DB::raw("jsonb_array_elements(CASE WHEN jsonb_typeof(raw->'staffAllocations') = 'array' THEN raw->'staffAllocations' ELSE '[]'::jsonb END) AS allocation"))
            ->selectRaw("DISTINCT coalesce(allocation->>'staffCode', allocation->>'staff') AS code")
            ->get()
            ->pluck('code');

        return $codes->merge($allocationCodes)
            ->flatMap(fn ($code) => preg_split('/[,，+\/|;；、]+/u', strtoupper(trim($code ?? ''))))
            ->map(fn ($code) => trim($code))
            ->filter(fn ($code) => preg_match('/^[A-Z0-9_-]{1,64}$/', $code))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * 查询关联订单。
     */
    public function orders(array $filters): iterable
    {
        // Imported raw payloads can contain multi-megabyte inline images. Reporting
        // needs only these staff/override fields, never the entire source snapshot.
        $query = Order::query()
            ->select([
                'id',
                'order_id',
                'client_order_id',
                'paypal_order_id',
                'visible_order_id',
                'entity_uuid',
                'order_time',
                'customer_name',
                'source_site',
                'classification',
                'influencer_name',
                'receiving_paypal',
                'amount_original',
                'currency',
                'amount_usd',
                'items_count',
                'product_name',
                'order_status',
                'staff_code',
                'version',
                'created_at',
                'updated_at',
            ])
            ->selectRaw("jsonb_build_object('orderId',raw->'orderId','staffAllocations',raw->'staffAllocations','primaryStaffCode',raw->'primaryStaffCode','dashboardEditedAt',raw->'dashboardEditedAt') as raw")
            ->orderBy('id');
        if (isset($filters['_orderId'])) {
            $query->whereKey($filters['_orderId']);
        }
        if (!empty($filters['startDate'])) {
            $query->where('order_time', '>=', CarbonImmutable::parse($filters['startDate'], 'Asia/Shanghai')->utc());
        }
        if (!empty($filters['endDate'])) {
            $query->where('order_time', '<', CarbonImmutable::parse($filters['endDate'], 'Asia/Shanghai')
                ->addDay()
                ->utc());
        }

        return $query->lazy(500);
    }

    public function invoices(array $filters): iterable
    {
        $query = InvoiceOrder::query()
            ->select([
                'id',
                'order_number',
                'order_date',
                'invoice_date',
                'customer_full_name',
                'recipient_paypal',
                'invoice_status',
                'amount_usd',
                'version',
            ])
            ->orderBy('id');
        if (!empty($filters['startDate'])) {
            $query->whereRaw('coalesce(order_date,invoice_date) >= ?', [$filters['startDate']]);
        }
        if (!empty($filters['endDate'])) {
            $query->whereRaw('coalesce(order_date,invoice_date) <= ?', [$filters['endDate']]);
        }

        return $query
            ->with(['items', 'allocations'])
            ->lazy(500);
    }

    public function overrides(): array
    {
        $result = [];
        foreach (OrderUserOverride::with('allocations')->get() as $override) {
            if ($override->order_uuid) {
                $result['uuid:' . $override->order_uuid] = $override;
            }
            $result[$override->order_key_type . ':' . $override->order_key] = $override;
        }

        return $result;
    }

    public function rates(): array
    {
        return ExchangeRate::orderBy('effective_date')
            ->get()
            ->groupBy('currency')
            ->all();
    }

    public function invoiceKeys(): array
    {
        return InvoiceOrder::pluck('order_number')
            ->mapWithKeys(fn ($key) => [$key => true])
            ->all();
    }

    /**
     * 加行锁读取记录。
     */
    public function lock(int $id): Order
    {
        return Order::whereKey($id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * 保存记录。
     */
    public function save(Order $order, array $data): void
    {
        $order
            ->fill($data)
            ->save();
    }
}
