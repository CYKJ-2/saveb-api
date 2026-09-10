<?php

namespace App\Dao;

use App\Models\ExchangeRate;
use App\Models\InvoiceItem;
use App\Models\InvoiceOrder;
use App\Models\InvoiceStaffAllocation;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Invoice 订单数据访问：封装模型查询与持久化操作。
 */
class InvoiceDao
{
    /** 列表公开字段；原始 OCR / 图片快照仅在详情查询时加载。 */
    private const LIST_COLUMNS = [
        'id', 'legacy_id', 'order_number', 'invoice_date', 'customer_full_name',
        'customer_email', 'phone_number', 'country', 'country_source', 'address',
        'invoice_link', 'invoice_status', 'expedited_shipping', 'fixed_discount',
        'percentage_discount', 'gift_box', 'amount_usd', 'recipient_paypal',
        'created_by', 'order_date', 'invoice_screenshot_attachment_id', 'entity_uuid',
        'version', 'created_at', 'updated_at', 'deleted_at',
    ];

    /**
     * 客服编码来自已启用用户及历史 Invoice 分摊，兼容尚未建立用户的旧编码。
     *
     * @return array 去重后的启用用户及历史 Invoice 客服编码列表
     */
    public function staffCodes(): array
    {
        return User::where('active', 1)->whereNotNull('staff_code')->pluck('staff_code')
            ->merge(InvoiceStaffAllocation::distinct()->pluck('staff_code'))
            ->map(fn ($code) => strtoupper(trim($code)))
            ->filter(fn ($code) => (bool) preg_match('/^[A-Z0-9_-]+$/', $code))
            ->unique()->sort()->values()->all();
    }

    /**
     * 每种币种只取指定日期前最近的一条有效汇率。
     *
     * @param  string  $date  业务日期，格式 Y-m-d
     * @return array 以币种为键的有效兑美元汇率映射
     */
    public function exchangeRates(string $date): array
    {
        return ExchangeRate::where('effective_date', '<=', $date)
            ->where('rate_to_usd', '>', 0)
            ->orderByDesc('effective_date')->orderByDesc('id')
            ->get(['currency', 'rate_to_usd'])
            ->unique('currency')
            ->mapWithKeys(fn ($rate) => [strtoupper($rate->currency) => (float) $rate->rate_to_usd])
            ->all();
    }

    /**
     * 按筛选条件分页查询 Invoice 订单。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件；本方法读取 keyword、startDate、endDate、per_page、page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<\App\Models\InvoiceOrder> Invoice 订单分页器，包含当前页记录、总条数和分页信息
     */
    public function listing(array $filters): LengthAwarePaginator
    {
        $query = InvoiceOrder::with(['items', 'allocations'])
            ->orderByRaw('coalesce(order_date,invoice_date) desc')
            ->orderByDesc('id');
        if (!empty($filters['keyword'])) {
            $query->where(fn ($keywordQuery) => $keywordQuery
                ->where('order_number', 'ilike', '%' . $filters['keyword'] . '%')
                ->orWhere('customer_full_name', 'ilike', '%' . $filters['keyword'] . '%')
                ->orWhere('customer_email', 'ilike', '%' . $filters['keyword'] . '%'));
        }
        if (!empty($filters['startDate'])) {
            $query->whereRaw('coalesce(order_date,invoice_date) >= ?', [$filters['startDate']]);
        }
        if (!empty($filters['endDate'])) {
            $query->whereRaw('coalesce(order_date,invoice_date) <= ?', [$filters['endDate']]);
        }

        $paginator = $query->paginate($filters['per_page'] ?? 20, self::LIST_COLUMNS, 'page', $filters['page'] ?? 1);
        if ($paginator->currentPage() > $paginator->lastPage()) {
            return $query->paginate($paginator->perPage(), self::LIST_COLUMNS, 'page', $paginator->lastPage());
        }

        return $paginator;
    }

    /**
     * 按主键读取 Invoice 订单详情。
     *
     * @param  int  $id  Invoice 订单记录主键 ID
     * @param  bool  $lock  是否加行锁；写入时由外层事务管理锁生命周期；默认 false
     * @return InvoiceOrder Invoice 订单模型实例
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException 指定业务记录不存在
     */
    public function find(int $id, bool $lock = false): InvoiceOrder
    {
        return InvoiceOrder::with(['items', 'allocations'])
            ->whereKey($id)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->firstOrFail();
    }

    /**
     * 计算下一个订单号。
     *
     * @return string 历史数字订单号最大值加一；最小为 10000
     */
    public function nextNumber(): string
    {
        return (string) max(
            10000,
            1 + (int) InvoiceOrder::withTrashed()
                ->whereRaw("order_number ~ '^[0-9]{1,12}\$'")
                ->selectRaw('max(order_number::bigint) as maximum')
                ->value('maximum'),
        );
    }

    /**
     * 保存 Invoice 订单及其关联数据。
     *
     * @param  InvoiceOrder|null  $invoice  Invoice 订单模型；null 表示不存在或尚未创建
     * @param  array  $data  经过 Controller 校验的业务字段
     * @param  array  $items  订单商品明细
     * @param  array  $allocations  客服分摊明细，包含员工编码与比例
     * @return InvoiceOrder Invoice 订单模型实例
     */
    public function save(
        ?InvoiceOrder $invoice,
        array $data,
        array $items,
        array $allocations,
    ): InvoiceOrder {
        $invoice ??= new InvoiceOrder();
        $invoice
            ->fill($data)
            ->save();
        InvoiceItem::withTrashed()
            ->where('invoice_id', $invoice->id)
            ->forceDelete();
        InvoiceStaffAllocation::withTrashed()
            ->where('invoice_id', $invoice->id)
            ->forceDelete();
        foreach ($items as $item) {
            InvoiceItem::create(array_intersect_key($item, array_flip([
                'product_name',
                'description',
                'quantity',
                'price',
                'notes',
                'image_attachment_id',
            ])) + ['invoice_id' => $invoice->id]);
        }
        foreach ($allocations as $item) {
            InvoiceStaffAllocation::create(array_intersect_key($item, array_flip(['staff_code', 'share_ratio', 'commission_percent'])) + ['invoice_id' => $invoice->id]);
        }

        return $this->find($invoice->id);
    }

    /**
     * 移除 Invoice 订单记录。
     *
     * @param  InvoiceOrder  $invoice  Invoice 订单模型
     * @return void 无返回值；副作用见方法说明
     */
    public function remove(InvoiceOrder $invoice): void
    {
        $invoice->delete();
    }
}
