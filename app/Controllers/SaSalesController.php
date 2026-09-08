<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Services\OrderManagementService;
use App\Services\SaSalesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SA 销售绩效接口：负责参数校验、调用服务和封装响应。
 */
class SaSalesController
{
    public function __construct(private SaSalesService $saSalesService)
    {
    }

    /**
     * 读取可用业务日期范围。
     */
    public function bounds(): JsonResponse
    {
        return AppResponse::success($this->saSalesService->bounds());
    }

    /** 读取明细筛选项，不提前返回订单数据。 */
    public function orderOptions(): JsonResponse
    {
        return AppResponse::success($this->saSalesService->orderOptions());
    }

    /** 独立查询订单明细；空销售分类表示全部普通订单。 */
    public function orders(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'startDate' => 'nullable|required_with:endDate|date_format:Y-m-d',
            'endDate' => 'nullable|required_with:startDate|date_format:Y-m-d|after_or_equal:startDate',
            'classification' => ['nullable', Rule::in(array_keys(OrderManagementService::CATEGORIES))],
            'orderStatus' => ['nullable', Rule::in(['completed', 'pending', 'failed', 'refunded', 'reversed', 'chargeback', 'returned', 'cancelled', 'expired'])],
            'orderId' => 'nullable|string|max:255',
            'customerName' => 'nullable|string|max:255',
            'customerService' => 'nullable|string|max:255',
            'paypalAccount' => 'nullable|string|max:255',
            'website' => 'nullable|string|max:255',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        return AppResponse::success($this->saSalesService->orders($filters));
    }

    /**
     * 导出数据。
     */
    public function export(Request $request, \App\Services\SaSalesExportService $saSalesExportService): StreamedResponse
    {
        $filters = $request->validate([
            'startDate' => 'required|date_format:Y-m-d',
            'endDate' => 'required|date_format:Y-m-d|after_or_equal:startDate',
        ]);

        return \App\Common\CsvResponse::lines(
            $saSalesExportService->lines($filters, \App\Common\ExportHeaders::locale()),
            'sa-sales.csv',
        );
    }

    /**
     * 生成绩效报表。
     */
    public function report(Request $request): JsonResponse
    {
        return AppResponse::success($this->saSalesService->report(['includeDetails' => false] + $request->validate([
            'startDate' => 'required|date_format:Y-m-d',
            'endDate' => 'required|date_format:Y-m-d|after_or_equal:startDate',
            'includeDetails' => 'sometimes|boolean',
        ])));
    }
}
