<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Services\LogisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogisticsController
{
    public function __construct(private LogisticsService $logisticsService)
    {
    }

    public function status(Request $request): JsonResponse
    {
        $data = $request->validate(['jobId' => 'nullable|uuid']);

        return AppResponse::success($this->logisticsService->status($data['jobId'] ?? null))->header('Cache-Control', 'no-store');
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate([
            'requestId' => 'required|uuid',
            'taskId' => 'nullable|integer|min:1',
            'provider' => 'sometimes|in:auto,aftership,kuaidi100',
        ]);

        return AppResponse::success($this->logisticsService->refresh($data, (int) $request->attributes->get('auth_user')->id), httpStatus: 202);
    }
}
