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
    /**
     * 注入 SA 销售处理所需的依赖。
     *
     * @param  SaSalesService  $saSalesService  SA 销售业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private SaSalesService $saSalesService)
    {
    }

    /**
     * 读取可用业务日期范围。
     *
     * @return JsonResponse 统一 JSON 响应；data 包含数据刷新时间 refreshedAt、最早日期 firstDate 和覆盖日期 dataThrough
     * @see SaSalesService::bounds()
     */
    public function bounds(): JsonResponse
    {
        return AppResponse::success($this->saSalesService->bounds());
    }

    /**
     * 读取明细筛选项，不提前返回订单数据。
     *
     * @return JsonResponse 统一 JSON 响应；data 为SA 销售的业务结果
     * @see SaSalesService::orderOptions()
     */
    public function orderOptions(): JsonResponse
    {
        return AppResponse::success($this->saSalesService->orderOptions());
    }

    /**
     * 独立查询订单明细；空销售分类表示全部普通订单。
     *
     * 请求字段（校验规则）：
     * - startDate：'nullable|required_with:endDate|date_format:Y-m-d'
     * - endDate：'nullable|required_with:startDate|date_format:Y-m-d|after_or_equal:startDate'
     * - classification：['nullable', Rule::in(array_keys(OrderManagementService::CATEGORIES))]
     * - orderStatus：['nullable', Rule::in(['completed', 'pending', 'failed', 'refunded', 'reversed', 'chargeback', 'returned', 'cancelled', 'expired'])]
     * - orderId：'nullable|string|max:255'
     * - customerName：'nullable|string|max:255'
     * - customerService：'nullable|string|max:255'
     * - paypalAccount：'nullable|string|max:255'
     * - website：'nullable|string|max:255'
     * - page：'sometimes|integer|min:1'
     * - per_page：'sometimes|integer|min:1|max:100'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 包含SA 销售列表及相应分页信息
     * @see SaSalesService::orders()
     */
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
     * 导出 SA 销售的完整筛选结果。
     *
     * 请求字段（校验规则）：
     * - startDate：'required|date_format:Y-m-d'
     * - endDate：'required|date_format:Y-m-d|after_or_equal:startDate'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @param  \App\Services\SaSalesExportService  $saSalesExportService  SA 销售导出业务服务
     * @return StreamedResponse CSV 流式下载响应，包含表头及导出数据
     * @see \App\Services\SaSalesExportService::lines()
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
     *
     * 请求字段（校验规则）：
     * - startDate：'required|date_format:Y-m-d'
     * - endDate：'required|date_format:Y-m-d|after_or_equal:startDate'
     * - includeDetails：'sometimes|boolean'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 包含员工排行、渠道统计、每日趋势、支付分布和总体销售指标
     * @see SaSalesService::report()
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
