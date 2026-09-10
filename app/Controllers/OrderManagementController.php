<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Services\OrderManagementService;
use App\Services\RbacService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 订单管理接口：负责参数校验、调用服务和封装响应。
 */
class OrderManagementController extends Controller
{
    /**
     * 注入 订单管理处理所需的依赖。
     *
     * @param  OrderManagementService  $orderManagementService  订单管理业务服务
     * @param  RbacService  $rbacService  有效权限业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private OrderManagementService $orderManagementService, private RbacService $rbacService)
    {
    }

    /**
     * 校验并整理筛选条件。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return array 通过校验的订单搜索字段及 page、per_page 分页参数
     * @throws \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface 业务校验、授权或资源可用性检查未通过
     */
    private function filters(Request $request): array
    {
        $rules = [
            'startDate' => 'nullable|required_with:endDate|date_format:Y-m-d',
            'endDate' => 'nullable|required_with:startDate|date_format:Y-m-d|after_or_equal:startDate',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'scope' => 'sometimes|in:normal,testing',
            'granularity' => 'sometimes|in:day,month',
            'staffExact' => 'nullable|boolean',
            'influencerExact' => 'nullable|boolean',
            'orderStatus' => 'nullable|in:completed,pending,failed,reversed,refunded,expired',
            'classification' => 'nullable|in:official,top_influencer,mid_influencer,offline,invoice,unmatched',
        ];
        foreach ([
            'orderId',
            'paypalOrderId',
            'customerName',
            'customerService',
            'paypalAccount',
            'website',
            'influencer',
        ] as $key) {
            $rules[$key] = 'nullable|string|max:255';
        }
        $filters = $request->validate($rules);
        if (($filters['scope'] ?? '') === 'testing') {
            abort_unless($this->canTest($request), 403);
        }

        return $filters;
    }

    /**
     * 检查是否可查询测试订单。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return bool 拥有通配权限或 system.order.testing 权限时为 true
     * @see RbacService::codes()
     */
    private function canTest(Request $request): bool
    {
        $codes = $this->rbacService->codes($request->attributes->get('auth_user'));

        return in_array('*', $codes, true) || in_array('system.order.testing', $codes, true);
    }

    /**
     * 查询订单管理列表。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 包含订单管理列表及相应分页信息
     * @see OrderManagementService::search()
     */
    public function index(Request $request): JsonResponse
    {
        return AppResponse::success($this->orderManagementService->search($this->filters($request), $this->canTest($request)));
    }

    /**
     * 获取订单编辑可选客服，不依赖 Invoice 或用户管理权限。
     *
     * @return JsonResponse 统一 JSON 响应；data 为订单管理的业务结果
     * @see OrderManagementService::editorOptions()
     */
    public function editorOptions(): JsonResponse
    {
        return AppResponse::success($this->orderManagementService->editorOptions());
    }

    /**
     * 导出订单管理的完整筛选结果。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return StreamedResponse CSV 流式下载响应，包含表头及导出数据
     * @see OrderManagementService::rows()
     */
    public function export(Request $request): StreamedResponse
    {
        return \App\Common\CsvResponse::download(
            $this->orderManagementService->rows($this->filters($request), $this->canTest($request)),
            [
                'orderId' => 'Order ID', 'paypalOrderId' => 'PayPal Order ID',
                'customerFullName' => 'Customer', 'clientSite' => 'Website',
                'paymentStatus' => 'Status', 'staff' => 'Staff', 'recipientPaypal' => 'PayPal',
                'amount' => 'Amount', 'currency' => 'Currency', 'date' => 'Date',
            ],
            'order-search.csv',
        );
    }

    /**
     * 校验版本并调整订单状态和客服分摊。
     *
     * 请求字段（校验规则）：
     * - version：'required|integer|min:0'
     * - targetStatus：'sometimes|in:completed'
     * - primaryStaffCode：'required|string|max:64|regex:/^[A-Za-z0-9_-]+$/'
     * - staffAllocations：'required|array|min:1|max:20'
     * - staffAllocations.*.staffCode：'required|string|max:64|regex:/^[A-Za-z0-9_-]+$/'
     * - staffAllocations.*.percent：'required|numeric|gt:0|lte:100'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @param  int  $id  订单管理记录主键 ID
     * @return JsonResponse 统一 JSON 响应；data 为订单管理的业务结果
     * @see OrderManagementService::adjust()
     */
    public function adjust(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'version' => 'required|integer|min:0',
            'targetStatus' => 'sometimes|in:completed',
            'primaryStaffCode' => 'required|string|max:64|regex:/^[A-Za-z0-9_-]+$/',
            'staffAllocations' => 'required|array|min:1|max:20',
            'staffAllocations.*.staffCode' => 'required|string|max:64|regex:/^[A-Za-z0-9_-]+$/',
            'staffAllocations.*.percent' => 'required|numeric|gt:0|lte:100',
        ]);

        // Test records require the same additional gate for mutations as for reads.
        return AppResponse::success($this->orderManagementService->adjust($id, $data, (int) $request->attributes->get('auth_user')->id, $this->canTest($request)));
    }
}
