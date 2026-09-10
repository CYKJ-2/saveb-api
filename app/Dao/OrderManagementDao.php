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
     * @return array<int, string> 去重后的在职及历史订单客服编码列表
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
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件；读取 _orderId、startDate、endDate；内部 _withProducts=false 时省略商品 JSON
     * @return iterable 按需迭代的订单管理记录，供逐条处理或导出
     */
    public function orders(array $filters): iterable
    {
        // 原始快照可能带有大体积图片；只读取客服覆盖字段及商品名称、链接、数量。
        $productProjection = ($filters['_withProducts'] ?? true) ? <<<'SQL'
            , 'products', (
                SELECT jsonb_agg(jsonb_build_object(
                    'name', product->'name',
                    'url', product->'url',
                    'quantity', product->'quantity'
                ) ORDER BY position)
                FROM jsonb_array_elements(
                    CASE WHEN jsonb_typeof(raw->'products') = 'array'
                        THEN raw->'products' ELSE '[]'::jsonb END
                ) WITH ORDINALITY AS source_product(product, position)
            )
            SQL : '';
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
            ->selectRaw(<<<SQL
                jsonb_build_object(
                    'orderId', raw->'orderId',
                    'staffAllocations', raw->'staffAllocations',
                    'primaryStaffCode', raw->'primaryStaffCode',
                    'dashboardEditedAt', raw->'dashboardEditedAt'
                    {$productProjection}
                ) AS raw
                SQL)
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

        // 避免 OFFSET 越翻越深时重复扫描、解包此前批次的原始 JSON。
        return $query->lazyById(500);
    }

    /**
     * 按业务日期读取 Invoice 订单及商品、客服分摊关联。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件；本方法读取 startDate、endDate
     * @return iterable 按需迭代的订单管理记录，供逐条处理或导出
     */
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
            ->with(['items:id,invoice_id,product_name,quantity', 'allocations'])
            ->lazyById(500);
    }

    /**
     * 读取原平台订单人工调整状态，供来源订单合并使用。
     *
     * @return array 原平台共享状态中的订单人工调整映射
     */
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

    /**
     * 读取按日期排列的币种汇率，供订单金额换算使用。
     *
     * @return array 供订单按日期选取的汇率记录列表
     */
    public function rates(): array
    {
        return ExchangeRate::orderBy('effective_date')
            ->get()
            ->groupBy('currency')
            ->all();
    }

    /**
     * 读取 Invoice 订单去重标识，防止与普通订单重复累计。
     *
     * @return array 以 Invoice 订单号为键、true 为值的去重索引
     */
    public function invoiceKeys(): array
    {
        return InvoiceOrder::pluck('order_number')
            ->mapWithKeys(fn ($key) => [$key => true])
            ->all();
    }

    /**
     * 加行锁读取记录。
     *
     * @param  int  $id  订单管理记录主键 ID
     * @return Order 订单管理模型实例
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException 指定业务记录不存在
     */
    public function lock(int $id): Order
    {
        return Order::whereKey($id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * 保存订单管理及其关联数据。
     *
     * @param  Order  $order  来源订单模型
     * @param  array  $data  经过 Controller 校验的业务字段
     * @return void 无返回值；副作用见方法说明
     */
    public function save(Order $order, array $data): void
    {
        $order
            ->fill($data)
            ->save();
    }
}
