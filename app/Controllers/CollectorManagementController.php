<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Services\CollectorManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollectorManagementController
{
    public function __construct(private CollectorManagementService $collectorManagementService)
    {
    }

    public function settings(): JsonResponse
    {
        return AppResponse::success($this->collectorManagementService->settings())->header('Cache-Control', 'no-store');
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['intervalMinutes' => 'required|integer|min:5|max:1440']);

        return AppResponse::success($this->collectorManagementService->saveSettings(
            $data['intervalMinutes'],
            (int) $request->attributes->get('auth_user')->id,
        ));
    }

    public function jobs(Request $request): JsonResponse
    {
        $data = $request->validate(\App\Common\PageResult::rules());

        return AppResponse::success($this->collectorManagementService->jobs($data['page'] ?? 1, $data['per_page'] ?? 20))->header('Cache-Control', 'no-store');
    }

    public function detail(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(\App\Common\PageResult::rules());

        return AppResponse::success($this->collectorManagementService->detail($id, $data['page'] ?? 1, $data['per_page'] ?? 20))->header('Cache-Control', 'no-store');
    }

    public function collect(Request $request): JsonResponse
    {
        return $this->submit($request, false);
    }

    public function reprocess(Request $request): JsonResponse
    {
        return $this->submit($request, true);
    }

    private function submit(Request $request, bool $reprocess): JsonResponse
    {
        $data = $request->validate([
            'requestId' => 'required|uuid',
            'mode' => $reprocess ? 'required|in:reprocess' : 'required|in:history,missing',
            'start' => 'required|date_format:Y-m-d|after_or_equal:2000-01-01',
            'end' => 'required|date_format:Y-m-d|after_or_equal:start|before_or_equal:' . now('Asia/Shanghai')->toDateString(),
            'dryRun' => 'sometimes|boolean',
            'sourceJobId' => $reprocess ? 'required|uuid' : 'prohibited',
        ]);

        return AppResponse::success($this->collectorManagementService->submit(
            $data,
            (int) $request->attributes->get('auth_user')->id,
        ), httpStatus: 202);
    }
}
