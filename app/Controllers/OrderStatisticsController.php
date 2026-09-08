<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Services\OrderStatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

/** Each statistics module has its own URL and permission gate. */
class OrderStatisticsController extends Controller
{
    public function __construct(private OrderStatisticsService $orderStatisticsService)
    {
    }

    /**
     * 读取详情。
     */
    public function show(Request $request, string $module): JsonResponse
    {
        $filters = $request->validate([
            'startDate' => 'required|date_format:Y-m-d',
            'endDate' => 'required|date_format:Y-m-d|after_or_equal:startDate',
            'granularity' => 'sometimes|in:day,month',
        ]);
        if (CarbonImmutable::parse($filters['startDate'])->diffInDays($filters['endDate']) > 366) {
            throw ValidationException::withMessages(['endDate' => '统计范围最多 366 天']);
        }

        return AppResponse::success($this->orderStatisticsService->statistics($module, $filters));
    }
}
