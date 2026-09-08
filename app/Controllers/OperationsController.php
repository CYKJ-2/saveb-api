<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Services\OperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 工作巡查接口：负责参数校验、调用服务和封装响应。
 */
class OperationsController
{
    public function __construct(private OperationsService $operationsService)
    {
    }

    /**
     * 查询列表。
     */
    public function index(Request $request): JsonResponse
    {
        return AppResponse::success($this->operationsService->directory($request->validate([
            'keyword' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:100',
        ])));
    }

    /** 查询在线表格目录及固定部门数量，用于工作巡查分组展示。 */
    public function directory(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:100',
        ]);

        return AppResponse::success($this->operationsService->report($filters));
    }
}
