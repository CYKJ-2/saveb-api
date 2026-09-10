<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Services\WarehouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 仓库处理接口：负责参数校验、调用服务和封装响应。
 */
class WarehouseController
{
    /**
     * 注入 仓库记录处理所需的依赖。
     *
     * @param  WarehouseService  $warehouseService  仓库记录业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private WarehouseService $warehouseService)
    {
    }

    /**
     * 查询仓库记录列表。
     *
     * 请求字段（校验规则）：
     * - keyword：'nullable|string|max:255'
     * - status：'nullable|in:' . implode(',', WarehouseService::STATUSES)
     * - scope：'nullable|in:recent,history,all'
     * - page：'nullable|integer|min:1'
     * - per_page：'sometimes|integer|min:1|max:100'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 包含仓库记录列表及相应分页信息
     * @see WarehouseService::listing()
     */
    public function index(Request $request): JsonResponse
    {
        return AppResponse::success($this->warehouseService->listing($request->validate([
            'keyword' => 'nullable|string|max:255',
            'status' => 'nullable|in:' . implode(',', WarehouseService::STATUSES),
            'scope' => 'nullable|in:recent,history,all',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ])));
    }

    /**
     * 校验质检、发货和版本参数，执行仓库状态变更。
     *
     * 请求字段（校验规则）：
     * - version：'required|integer|min:1'
     * - status：'required|in:' . implode(',', WarehouseService::STATUSES)
     * - notes：'nullable|string|max:4000'
     * - items：'required|array|min:1|max:100'
     * - items.*.inspection：'required|in:pending,passed,failed'
     * - items.*.shippedQuantity：'required|integer|min:0|max:10000'
     * - items.*.outboundTracking：'nullable|string|max:255'
     * - items.*.notes：'nullable|string|max:2000'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @param  int  $id  仓库记录记录主键 ID
     * @return JsonResponse 统一 JSON 响应；data 为仓库记录的业务结果
     * @see WarehouseService::action()
     */
    public function action(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'version' => 'required|integer|min:1',
            'status' => 'required|in:' . implode(',', WarehouseService::STATUSES),
            'notes' => 'nullable|string|max:4000',
            'items' => 'required|array|min:1|max:100',
            'items.*.inspection' => 'required|in:pending,passed,failed',
            'items.*.shippedQuantity' => 'required|integer|min:0|max:10000',
            'items.*.outboundTracking' => 'nullable|string|max:255',
            'items.*.notes' => 'nullable|string|max:2000',
        ]);

        return AppResponse::success($this->warehouseService->action($id, $data, (int) $request->attributes->get('auth_user')->id));
    }
}
