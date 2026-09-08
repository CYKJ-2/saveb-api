<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Common\RespDef;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * 订单管理控制器。
 *
 * 接口与前端 /api/order-search 行为完全对齐：
 *   - GET    /api/orders                  → 分页查询（带筛选条件）
 *   - GET    /api/orders/{id}             → 单条订单详情
 *   - POST   /api/orders                  → 创建订单
 *   - PUT    /api/orders/{id}             → 更新订单（支持乐观锁 version）
 *   - DELETE /api/orders/{id}             → 软删除订单
 *   - GET    /api/orders/count            → 统计订单总数
 *
 * 端点权限码：
 *   - 读操作：system.order.list （或 super_admin 通配 '*'）
 *   - 写操作：system.order.create / .update / .delete
 *
 * 筛选参数（GET /api/orders）：
 *   - orderId          string  精确匹配 client_order_id
 *   - paypalOrderId    string  精确匹配 paypal_order_id
 *   - customerName     string  模糊匹配 customer_name
 *   - customerService  string  模糊匹配 staff_code
 *   - paypalAccount    string  模糊匹配 receiving_paypal
 *   - website          string  模糊匹配 source_site
 *   - orderStatus      string  模糊匹配 order_status
 *   - startDate        date    下单时间起点（含），格式 YYYY-MM-DD
 *   - endDate          date    下单时间终点（含），格式 YYYY-MM-DD
 *   - page             int     1-based 页码，默认 1
 *   - perPage          int     每页条数（最大 100），默认 20
 *   - sortBy           string  排序列，默认 order_time
 *   - sortDir          string  asc | desc，默认 desc
 */
class OrderController extends BaseController
{
    /**
     * 构造函数，注入订单服务层。
     *
     * @param  OrderService  $orderService  订单业务服务
     */
    public function __construct(private readonly OrderService $orderService)
    {
    }

    /**
     * GET /api/orders
     *
     * 与 saveb-erp /api/order-search 等价的分页查询接口。
     *
     * @param  Request  $request  HTTP 请求对象
     * @return JsonResponse       分页结果（list + total + page + per_page + last_page）
     */
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $sortBy = (string) $request->query('sort_by', 'order_time');
        $sortDir = (string) $request->query('sort_dir', 'desc');
        $criteria = [
            'orderId' => $request->query('orderId'),
            'paypalOrderId' => $request->query('paypalOrderId'),
            'customerName' => $request->query('customerName'),
            'customerService' => $request->query('customerService'),
            'paypalAccount' => $request->query('paypalAccount'),
            'website' => $request->query('website'),
            'orderStatus' => $request->query('orderStatus'),
            'startDate' => $request->query('startDate'),
            'endDate' => $request->query('endDate'),
        ];

        return AppResponse::success($this->orderService->search($criteria, $page, $perPage, $sortBy, $sortDir));
    }

    /**
     * GET /api/orders/count
     *
     * 统计订单总数，可按状态过滤。
     *
     * @param  Request  $request  HTTP 请求对象
     * @return JsonResponse       { total: int, status: string|null }
     */
    public function count(Request $request): JsonResponse
    {
        $status = $request->query('orderStatus');

        return AppResponse::success([
            'total' => $this->orderService->count(is_string($status) ? $status : null),
            'status' => $status,
        ]);
    }

    /**
     * GET /api/orders/{id}
     *
     * 获取单个订单详情。不存在时抛 404 + CODE_ORDER_NOT_FOUND。
     *
     * @param  int          $id  订单主键 ID
     * @return JsonResponse      订单详情数组
     */
    public function show(int $id): JsonResponse
    {
        return AppResponse::success($this->orderService->find($id));
    }

    /**
     * POST /api/orders
     *
     * 创建新订单。所有字段除 order_status 外均为可选；
     * 不提供 client_order_id / paypal_order_id 时也不会报错（仅校验已提供字段的唯一性）。
     *
     * 请求体字段：
     *   orderId         string? 客户端订单号（= client_order_id 别名）
     *   clientOrderId   string? 客户端订单号
     *   paypalOrderId   string? PayPal 订单号
     *   orderTime       string? ISO 8601 时间字符串
     *   customerName    string? 顾客姓名
     *   sourceSite      string? 来源网站
     *   classification  string? 归类
     *   influencerName  string? 关联达人
     *   receivingPaypal string? 收款 PayPal 账号
     *   amountOriginal  number? 原始金额
     *   currency        string? 币种
     *   amountUsd       number? 美元金额
     *   itemsCount      int?    商品件数，默认 1
     *   productName     string? 商品名称
     *   orderStatus     string? 订单状态
     *   staffCode       string? 主负责客服
     *   raw             array?  原始 JSON 负载
     *
     * @param  Request  $request  HTTP 请求对象
     * @return JsonResponse       HTTP 201，新创建的订单
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'orderId' => 'nullable|string|max:128',
            'clientOrderId' => 'nullable|string|max:128',
            'paypalOrderId' => 'nullable|string|max:128',
            'orderTime' => 'nullable|date',
            'customerName' => 'nullable|string|max:255',
            'sourceSite' => 'nullable|string|max:255',
            'classification' => 'nullable|string|max:64',
            'influencerName' => 'nullable|string|max:255',
            'receivingPaypal' => 'nullable|string|max:255',
            'amountOriginal' => 'nullable|numeric',
            'currency' => 'nullable|string|max:16',
            'amountUsd' => 'nullable|numeric',
            'itemsCount' => 'nullable|integer|min:1',
            'productName' => 'nullable|string|max:500',
            'orderStatus' => 'nullable|string|max:64',
            'staffCode' => 'nullable|string|max:64',
            'raw' => 'nullable|array',
        ]);

        return AppResponse::success($this->orderService->create($data), null, RespDef::CODE_SUCCESS, [], 201);
    }

    /**
     * PUT /api/orders/{id}
     *
     * 更新已有订单。乐观锁：通过 version 字段做 CAS。
     *
     * 请求体：与 create 一致，但所有字段变为可选；可额外传 version 用于 CAS。
     *
     * @param  int      $id      订单主键 ID
     * @param  Request  $request HTTP 请求对象
     * @return JsonResponse      更新后的订单
     */
    public function update(int $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'orderId' => 'sometimes|nullable|string|max:128',
            'clientOrderId' => 'sometimes|nullable|string|max:128',
            'paypalOrderId' => 'sometimes|nullable|string|max:128',
            'orderTime' => 'sometimes|nullable|date',
            'customerName' => 'sometimes|nullable|string|max:255',
            'sourceSite' => 'sometimes|nullable|string|max:255',
            'classification' => 'sometimes|nullable|string|max:64',
            'influencerName' => 'sometimes|nullable|string|max:255',
            'receivingPaypal' => 'sometimes|nullable|string|max:255',
            'amountOriginal' => 'sometimes|nullable|numeric',
            'currency' => 'sometimes|nullable|string|max:16',
            'amountUsd' => 'sometimes|nullable|numeric',
            'itemsCount' => 'sometimes|nullable|integer|min:1',
            'productName' => 'sometimes|nullable|string|max:500',
            'orderStatus' => 'sometimes|nullable|string|max:64',
            'staffCode' => 'sometimes|nullable|string|max:64',
            'raw' => 'sometimes|nullable|array',
            'version' => 'sometimes|nullable|integer|min:1',
        ]);

        return AppResponse::success($this->orderService->update($id, $data));
    }

    /**
     * DELETE /api/orders/{id}
     *
     * 软删除订单。不存在时抛 404 + CODE_ORDER_NOT_FOUND。
     *
     * @param  int          $id  订单主键 ID
     * @return JsonResponse      { deleted: true }
     */
    public function destroy(int $id): JsonResponse
    {
        $this->orderService->delete($id);

        return AppResponse::success(['deleted' => true]);
    }
}
