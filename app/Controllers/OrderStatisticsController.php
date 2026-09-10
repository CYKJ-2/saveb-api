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
    /**
     * 注入 订单统计处理所需的依赖。
     *
     * @param  OrderStatisticsService  $orderStatisticsService  订单统计业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private OrderStatisticsService $orderStatisticsService)
    {
    }

    /**
     * 按模块及筛选条件读取订单统计数据。
     *
     * 请求字段（校验规则）：
     * - startDate：'required|date_format:Y-m-d'
     * - endDate：'required|date_format:Y-m-d|after_or_equal:startDate'
     * - granularity：'sometimes|in:day,month'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @param  string  $module  要查询的统计或业务模块标识
     * @return JsonResponse 统一 JSON 响应；data 为订单统计的模块数据
     * @see OrderStatisticsService::statistics()
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
