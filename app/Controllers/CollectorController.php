<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Services\CollectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 首页状态查询和手动采集入口，权限由路由独立控制。 */
class CollectorController
{
    public function __construct(private CollectorService $collectorService)
    {
    }

    public function status(): JsonResponse
    {
        return AppResponse::success($this->collectorService->status())->header('Cache-Control', 'no-store');
    }

    public function today(Request $request): JsonResponse
    {
        $validatedData = $request->validate(['requestId' => 'required|uuid']);

        return AppResponse::success($this->collectorService->collectToday(
            (int) $request->attributes->get('auth_user')->id,
            $validatedData['requestId'],
        ), httpStatus: 202);
    }
}
