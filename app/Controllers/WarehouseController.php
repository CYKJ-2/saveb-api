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
    public function __construct(private WarehouseService $warehouseService)
    {
    }

    /**
     * 查询列表。
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
