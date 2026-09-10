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
    /**
     * 注入 首页概览处理所需的依赖。
     *
     * @param  DashboardOverviewService  $dashboardOverviewService  首页概览业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private DashboardOverviewService $dashboardOverviewService)
    {
    }

    /**
     * 按日期范围读取指定首页统计模块。
     *
     * 请求字段（校验规则）：
     * - startDate：'sometimes|required|date_format:Y-m-d'
     * - endDate：'sometimes|required|date_format:Y-m-d'
     * - granularity：'sometimes|required|in:day,month'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @param  string  $module  要查询的统计或业务模块标识
     * @return JsonResponse 统一 JSON 响应；data 为首页概览的模块数据
     * @see DashboardOverviewService::show()
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
