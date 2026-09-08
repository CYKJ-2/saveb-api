<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Services\DashboardOverviewService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 首页概览接口：负责参数校验、调用服务和封装响应。
 */
class DashboardOverviewController
{
    public function __construct(private DashboardOverviewService $dashboardOverviewService)
    {
    }

    /**
     * 读取详情。
     */
    public function show(Request $request, string $module): JsonResponse
    {
        $input = $request->validate([
            'startDate' => 'sometimes|required|date_format:Y-m-d',
            'endDate' => 'sometimes|required|date_format:Y-m-d',
            'granularity' => 'sometimes|required|in:day,month',
        ]);
        $today = now('Asia/Shanghai')->toDateString();
        $start = $input['startDate'] ?? $input['endDate'] ?? $today;
        $end = $input['endDate'] ?? $input['startDate'] ?? $today;
        if ($start > $end || CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) > 365) {
            throw ValidationException::withMessages(['endDate' => '结束日期不能早于开始日期，查询范围最多 366 天']);
        }

        return AppResponse::success($this->dashboardOverviewService->show($module, [
            'startDate' => $start,
            'endDate' => $end,
        ], $input['granularity'] ?? 'day'));
    }
}
