<?php

namespace App\Services;

use App\Common\RespDef;
use App\Dao\OrderDao;
use App\Exceptions\SystemException;
use App\Models\Order;

/**
 * 订单业务服务。
 *
 * 架构：OrderController → OrderService → OrderDao → Order (Model)
 *
 * 职责范围：
 *   - 订单的增删改查（CRUD）
 *   - 与 /api/order-search 完全等价的筛选逻辑
 *   - 唯一性校验：client_order_id / paypal_order_id / order_id
 *   - 乐观锁（version 字段）写入
 *
 * 设计要点：
 *   - order_id 是系统内全局唯一的主订单号，由后端生成（visible_order_id 序列风格）
 *   - client_order_id 是外部客户端订单号，可选但创建时若提供需唯一
 *   - paypal_order_id 同理
 *   - 修改时 version 必须匹配当前值，否则抛 409
 */
class OrderService
{
    /**
     * 构造函数，注入订单 DAO。
     *
     * @param  OrderDao  $orderDao  订单数据访问对象
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private readonly OrderDao $orderDao)
    {
    }

    /**
     * 与 /api/order-search 等价的分页查询。
     *
     * 字段命名沿用前端表单（与 saveb-erp 完全一致）：
     *   - orderId, paypalOrderId, customerName, customerService,
     *     paypalAccount, website, orderStatus, startDate, endDate
     *
     * 返回的 Paginator 中的每条订单已经按 Order::present() 序列化，
     * 字段名沿用 saveb-erp 的 camelCase 命名，便于前端直接渲染。
     *
     * @param  array<string,mixed>  $criteria  筛选条件（字段名见 OrderDao::search）
     * @param  int  $page  1-based 页码
     * @param  int  $perPage  每页条数
     * @param  string  $sortBy  排序列
     * @param  string  $sortDir  asc | desc
     * @return array{ list: array<int,array<string,mixed>>, total: int, page: int, per_page: int, last_page: int, criteria: array<string,mixed> } 订单结果数组；返回字段：list、total、page、per_page、last_page、criteria
     * @see OrderDao::search()
     */
    public function search(
        array $criteria,
        int $page = 1,
        int $perPage = 20,
        string $sortBy = 'order_time',
        string $sortDir = 'desc',
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        // 规范化空字符串为 null，避免 "" 进入 LIKE 匹配
        $criteria = $this->normalizeCriteria($criteria);
        $paginator = $this->orderDao->search($criteria, $perPage, $page, $sortBy, $sortDir);

        return [
            'list' => collect($paginator->items())
                ->map(fn (Order $order) => Order::present($order))
                ->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'criteria' => $criteria,
        ];
    }

    /**
     * 按主键 ID 获取单个订单（含完整字段）。
     *
     * @param  int  $id  订单主键 ID
     * @return array Order::present 序列化结果
     * @throws SystemException  订单不存在时抛 404 + CODE_ORDER_NOT_FOUND
     * @see OrderDao::findFull()
     */
    public function find(int $id): array
    {
        $order = $this->orderDao->findFull($id);
        if (!$order) {
            throw new SystemException(RespDef::CODE_ORDER_NOT_FOUND, RespDef::MSG_ORDER_NOT_FOUND, 404);
        }

        return Order::present($order);
    }

    /**
     * 创建一条订单。
     *
     * 校验：
     *   - 若提供 client_order_id / paypal_order_id / order_id，必须全局唯一
     *
     * @param  array<string,mixed>  $data  字段（参见 OrderController::store）
     * @return array Order::present 序列化结果
     * @throws SystemException  唯一性冲突时抛 422 + CODE_ORDER_ALREADY_EXISTS
     * @see OrderDao::create()
     */
    public function create(array $data): array
    {
        $payload = $this->preparePayload($data, null);
        // 唯一性预检
        $this->assertUniqueIdentifiers($payload, null);
        $order = $this->orderDao->create($payload);

        return Order::present($order);
    }

    /**
     * 更新已有订单。
     *
     * 支持乐观锁：请求体提供 version 时必须与当前一致，否则抛 409。
     *
     * @param  int  $id  订单主键 ID
     * @param  array<string,mixed>  $data  待更新字段（可选 version）
     * @return array Order::present 序列化结果
     * @throws SystemException  订单不存在 / version 冲突 / 唯一性冲突
     * @see OrderDao::findFull()
     * @see OrderDao::updateWhere()
     */
    public function update(int $id, array $data): array
    {
        $order = $this->orderDao->findFull($id);
        if (!$order) {
            throw new SystemException(RespDef::CODE_ORDER_NOT_FOUND, RespDef::MSG_ORDER_NOT_FOUND, 404);
        }
        // 乐观锁校验
        if (array_key_exists('version', $data) && $data['version'] !== null) {
            $incoming = (int) $data['version'];
            if ($incoming !== (int) $order->version) {
                throw new SystemException(RespDef::CODE_OPERATION_FAILED, '订单版本已过期，请刷新后重试。', 409);
            }
        }
        $payload = $this->preparePayload($data, $order);
        // 唯一性预检（排除自身）
        $this->assertUniqueIdentifiers($payload, $order);
        if (!empty($payload)) {
            $payload['version'] = (int) $order->version + 1;
            $this->orderDao->updateWhere(['id' => $id], $payload);
        }
        $fresh = $this->orderDao->findFull($id);

        return Order::present($fresh);
    }

    /**
     * 软删除订单。
     *
     * @param  int  $id  订单主键 ID
     * @return void 无返回值；副作用见方法说明
     * @throws SystemException  订单不存在
     * @see OrderDao::findFull()
     * @see OrderDao::deleteWhere()
     */
    public function delete(int $id): void
    {
        $order = $this->orderDao->findFull($id);
        if (!$order) {
            throw new SystemException(RespDef::CODE_ORDER_NOT_FOUND, RespDef::MSG_ORDER_NOT_FOUND, 404);
        }
        $this->orderDao->deleteWhere(['id' => $id]);
    }

    /**
     * 统计订单总数（可选按状态过滤）。
     *
     * @param  string|null  $status  订单状态过滤
     * @return int 订单总数
     */
    public function count(?string $status = null): int
    {
        $query = Order::query();
        if ($status !== null && $status !== '') {
            $query->where('order_status', $status);
        }

        return $query->count();
    }

    /* ─── Helpers ──────────────────────────────────── */
    /**
     * 规范化筛选条件：trim + 空串 → null。
     *
     * @param  array<string,mixed>  $criteria  订单查询条件
     * @return array<string,mixed> 去除首尾空白并将空字符串转换为 null 的订单筛选条件
     */
    private function normalizeCriteria(array $criteria): array
    {
        $out = [];
        foreach ($criteria as $key => $value) {
            if (is_string($value)) {
                $trimmed = trim($value);
                $out[$key] = $trimmed === '' ? null : $trimmed;
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * 把入参转换为可写入的字段集合。
     *
     *   - camelCase → snake_case 映射
     *   - 自动生成 order_id（若未提供）
     *   - 自动设置 currency 默认 USD
     *   - 默认 items_count=1
     *
     * @param  array<string,mixed>  $data  入参
     * @param  Order|null  $order  当前订单（更新时传入，用于字段合并）
     * @return array<string,mixed> 可写入字段
     */
    private function preparePayload(array $data, ?Order $order): array
    {
        // camelCase → snake_case 映射
        $map = [
            'orderId' => 'client_order_id',
            'clientOrderId' => 'client_order_id',
            'paypalOrderId' => 'paypal_order_id',
            'orderTime' => 'order_time',
            'customerName' => 'customer_name',
            'sourceSite' => 'source_site',
            'classification' => 'classification',
            'influencerName' => 'influencer_name',
            'receivingPaypal' => 'receiving_paypal',
            'amountOriginal' => 'amount_original',
            'currency' => 'currency',
            'amountUsd' => 'amount_usd',
            'itemsCount' => 'items_count',
            'productName' => 'product_name',
            'orderStatus' => 'order_status',
            'staffCode' => 'staff_code',
            'raw' => 'raw',
        ];
        $payload = [];
        foreach ($data as $key => $value) {
            $snake = $map[$key] ?? $key;
            // 不允许通过 API 改 entity_uuid / visible_order_id / id / created_at
            if (in_array($snake, [
                'entity_uuid',
                'visible_order_id',
                'id',
                'created_at',
                'updated_at',
                'deleted_at',
                'version',
            ], true)) {
                continue;
            }
            if ($value !== null) {
                $payload[$snake] = $value;
            }
        }
        // 创建时：若 order_id 缺失则自动生成（VIS-<visible_order_id>）
        if ($order === null) {
            if (empty($payload['order_id'])) {
                // 先占位，等 visible_order_id 由序列触发后写入；
                // 若调用方已提供 client_order_id，则把它也作为 order_id 兜底
                if (!empty($payload['client_order_id'])) {
                    $payload['order_id'] = $payload['client_order_id'];
                } else {
                    $payload['order_id'] = 'ORD-' . strtoupper(bin2hex(random_bytes(6)));
                }
            }
            if (!isset($payload['items_count'])) {
                $payload['items_count'] = 1;
            }
            if (!isset($payload['currency'])) {
                $payload['currency'] = 'USD';
            }
        }

        return $payload;
    }

    /**
     * 唯一性预检：order_id / client_order_id / paypal_order_id 三者分别独立判断。
     *
     * @param  array<string,mixed>  $payload  待写入字段
     * @param  Order|null  $exclude  排除的订单（更新自身时传入）
     * @return void 无返回值；副作用见方法说明
     * @throws SystemException  任意字段重复时抛 422
     */
    private function assertUniqueIdentifiers(array $payload, ?Order $exclude): void
    {
        $checks = [
            ['order_id', $payload['order_id'] ?? null],
            ['client_order_id', $payload['client_order_id'] ?? null],
            ['paypal_order_id', $payload['paypal_order_id'] ?? null],
        ];
        foreach ($checks as [$field, $value]) {
            if ($value === null || $value === '') {
                continue;
            }
            $query = Order::query()->where($field, $value);
            if ($exclude) {
                $query->where('id', '!=', $exclude->id);
            }
            if ($query->exists()) {
                throw new SystemException(RespDef::CODE_ORDER_ALREADY_EXISTS, sprintf('订单 %s 已存在：%s', $field, $value), 422);
            }
        }
    }
}
