<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Common\PageResult;
use App\Services\SaPersonalPerformanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** SA 页面底部个人业绩：校验员工、日期与分页参数。 */
class SaPersonalPerformanceController
{
    /**
     * 注入个人业绩业务服务。
     *
     * @param SaPersonalPerformanceService $personalPerformanceService 个人业绩查询及统计服务
     * @return void 完成依赖初始化
     */
    public function __construct(private SaPersonalPerformanceService $personalPerformanceService)
    {
    }

    /**
     * 查询在职及历史客服，并返回当前登录用户的默认客服编码。
     *
     * @param Request $request 已通过 Bearer 认证的请求，读取 auth_user.staff_code
     * @return JsonResponse data 包含 employees（编码、显示名称）及 defaultStaffCode
     */
    public function options(Request $request): JsonResponse
    {
        return AppResponse::success($this->personalPerformanceService->options(
            $request->attributes->get('auth_user')?->staff_code,
        ));
    }

    /**
     * 按员工及北京时间闭区间查询个人汇总、补齐日期的趋势和分页订单。
     *
     * @param Request $request staffCode；startDate/endDate（Y-m-d，最多 366 天）；scope（all/order/invoice）；page/per_page（默认 1/20）；includeSummary（翻页传 false）
     * @return JsonResponse data 包含 range、summary、daily、orders；金额统一为 USD，保留两位小数
     */
    public function report(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'staffCode' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'startDate' => 'required|date_format:Y-m-d',
            'endDate' => 'required|date_format:Y-m-d|after_or_equal:startDate',
            'scope' => 'sometimes|in:all,order,invoice',
            'includeSummary' => 'sometimes|boolean',
        ] + PageResult::rules());
        if (CarbonImmutable::parse($filters['startDate'])->diffInDays($filters['endDate']) > 365) {
            throw ValidationException::withMessages(['endDate' => 'PERSONAL_DATE_RANGE_TOO_LONG']);
        }

        return AppResponse::success($this->personalPerformanceService->report($filters));
    }
}
