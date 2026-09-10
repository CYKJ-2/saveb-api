<?php

namespace App\Dao;

use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 订单数据访问对象。
 *
 * @extends BaseDao<Order>
 */
class OrderDao extends BaseDao
{
    /**
     * 返回当前 DAO 关联的模型类。
     *
     * @return class-string<Order> 模型类名
     */
    protected function model(): string
    {
        return Order::class;
    }

    /**
     * 按 order_id 查找订单（系统订单号，全局唯一）。
     *
     * @param  string  $orderId  系统订单号
     * @return Order|null 不存在时返回 null
     */
    public function findByOrderId(string $orderId): ?Order
    {
        return $this
            ->query()
            ->where('order_id', $orderId)
            ->first();
    }

    /**
     * 按 client_order_id 查找订单。
     *
     * @param  string  $clientOrderId  客户端订单号
     * @return Order|null 不存在时返回 null
     */
    public function findByClientOrderId(string $clientOrderId): ?Order
    {
        return $this
            ->query()
            ->where('client_order_id', $clientOrderId)
            ->first();
    }

    /**
     * 按 paypal_order_id 查找订单。
     *
     * @param  string  $paypalOrderId  PayPal 订单号
     * @return Order|null 不存在时返回 null
     */
    public function findByPaypalOrderId(string $paypalOrderId): ?Order
    {
        return $this
            ->query()
            ->where('paypal_order_id', $paypalOrderId)
            ->first();
    }

    /**
     * 按 entity_uuid 查找订单（跨系统对账用）。
     *
     * @param  string  $uuid  UUID 字符串
     * @return Order|null 不存在时返回 null
     */
    public function findByEntityUuid(string $uuid): ?Order
    {
        return $this
            ->query()
            ->where('entity_uuid', $uuid)
            ->first();
    }

    /**
     * /api/order-search 接口对应的筛选查询。
     *
     * 筛选条件（与 saveb-erp 端完全一致）：
     *   - orderId          : 精确匹配 client_order_id
     *   - paypalOrderId    : 精确匹配 paypal_order_id
     *   - customerName     : 模糊匹配 customer_name（ILIKE %name%）
     *   - customerService  : 模糊匹配 staff_code（拼接 staffAllocations）
     *   - paypalAccount    : 模糊匹配 receiving_paypal
     *   - website          : 模糊匹配 source_site
     *   - orderStatus      : 模糊匹配 order_status
     *   - startDate / endDate : order_time 区间（闭区间，按 Asia/Shanghai 日历日）
     *
     * 返回 LengthAwarePaginator，分页参数由调用方控制。
     *
     * @param  array{ orderId?: ?string, paypalOrderId?: ?string, customerName?: ?string, customerService?: ?string, paypalAccount?: ?string, website?: ?string, orderStatus?: ?string, startDate?: ?string, endDate?: ?string, }  $criteria  筛选条件，全部可选
     * @param  int  $perPage  每页条数
     * @param  int  $page  1-based 页码
     * @param  string  $sortBy  排序列，默认 order_time
     * @param  string  $sortDir  asc | desc，默认 desc
     * @return LengthAwarePaginator<Order> 订单分页器，包含当前页记录、总条数和分页信息
     */
    public function search(
        array $criteria,
        int $perPage = 20,
        int $page = 1,
        string $sortBy = 'order_time',
        string $sortDir = 'desc',
    ): LengthAwarePaginator {
        $query = $this->query();
        // ── 精确匹配 ─────────────────────────────────
        if (!empty($criteria['orderId'])) {
            $query->where('client_order_id', trim((string) $criteria['orderId']));
        }
        if (!empty($criteria['paypalOrderId'])) {
            $query->where('paypal_order_id', trim((string) $criteria['paypalOrderId']));
        }
        // ── 模糊匹配 ─────────────────────────────────
        if (!empty($criteria['customerName'])) {
            $name = '%' . trim((string) $criteria['customerName']) . '%';
            $query->where('customer_name', 'ilike', $name);
        }
        if (!empty($criteria['customerService'])) {
            $customerService = '%' . trim((string) $criteria['customerService']) . '%';
            // 与 saveb-erp matchesCriteria 对齐：在 staff_code 上做 ILIKE
            $query->where('staff_code', 'ilike', $customerService);
        }
        if (!empty($criteria['paypalAccount'])) {
            $paypal = '%' . trim((string) $criteria['paypalAccount']) . '%';
            $query->where('receiving_paypal', 'ilike', $paypal);
        }
        if (!empty($criteria['website'])) {
            $site = '%' . trim((string) $criteria['website']) . '%';
            $query->where('source_site', 'ilike', $site);
        }
        if (!empty($criteria['orderStatus'])) {
            $status = '%' . trim((string) $criteria['orderStatus']) . '%';
            $query->where('order_status', 'ilike', $status);
        }
        // ── 时间范围 ─────────────────────────────────
        // 按业务日（Asia/Shanghai）解析，避免跨时区误差
        $start = $criteria['startDate'] ?? null;
        $end = $criteria['endDate'] ?? null;
        if ($start || $end) {
            $startTs = $start ? \Carbon\Carbon::parse($start)
                ->startOfDay()
                ->utc()
                ->toDateTimeString() : null;
            $endTs = $end ? \Carbon\Carbon::parse($end)
                ->endOfDay()
                ->utc()
                ->toDateTimeString() : null;
            if ($startTs && $endTs) {
                $query->whereBetween('order_time', [$startTs, $endTs]);
            } elseif ($startTs) {
                $query->where('order_time', '>=', $startTs);
            } else {
                $query->where('order_time', '<=', $endTs);
            }
        }
        // ── 排序 ──────────────────────────────────────
        $sortDir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';
        // 仅允许已知字段排序，避免 SQL 注入
        $allowedSort = [
            'id',
            'order_time',
            'created_at',
            'amount_original',
            'amount_usd',
            'items_count',
            'visible_order_id',
        ];
        if (!in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'order_time';
        }
        $query->orderBy($sortBy, $sortDir);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * 按主键加载订单（含预加载关系）。
     *
     * @param  int  $id  订单主键 ID
     * @return Order|null 订单模型实例；未找到时返回 null
     */
    public function findFull(int $id): ?Order
    {
        return $this
            ->query()
            ->find($id);
    }
}
