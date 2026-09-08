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
    /** 客服编码来自已启用用户及历史 Invoice 分摊，兼容尚未建立用户的旧编码。 */
    public function staffCodes(): array
    {
        return User::where('active', 1)->whereNotNull('staff_code')->pluck('staff_code')
            ->merge(InvoiceStaffAllocation::distinct()->pluck('staff_code'))
            ->map(fn ($code) => strtoupper(trim($code)))
            ->filter(fn ($code) => (bool) preg_match('/^[A-Z0-9_-]+$/', $code))
            ->unique()->sort()->values()->all();
    }

    /** 每种币种只取指定日期前最近的一条有效汇率。 */
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
     * 分页查询。
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<\App\Models\InvoiceOrder>
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

        $paginator = $query->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
        if ($paginator->currentPage() > $paginator->lastPage()) {
            return $query->paginate($paginator->perPage(), ['*'], 'page', $paginator->lastPage());
        }

        return $paginator;
    }

    /**
     * 按标识查询记录。
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
     * 保存记录。
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
     * 移除记录。
     */
    public function remove(InvoiceOrder $invoice): void
    {
        $invoice->delete();
    }
}
