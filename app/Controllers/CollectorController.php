<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Services\CollectorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 首页状态查询和手动采集入口，权限由路由独立控制。 */
class CollectorController
{
    /**
     * 注入 采集运行状态处理所需的依赖。
     *
     * @param  CollectorService  $collectorService  采集运行状态业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private CollectorService $collectorService)
    {
    }

    /**
     * 读取当前数据库的采集任务、调度心跳及执行进度。
     *
     * @return JsonResponse 统一 JSON 响应；data 包含采集服务可用性、调度心跳、当前任务及最近成功任务等状态
     * @see CollectorService::status()
     */
    public function status(): JsonResponse
    {
        return AppResponse::success($this->collectorService->status())->header('Cache-Control', 'no-store');
    }

    /**
     * 校验幂等请求编号并提交当日采集任务。
     *
     * 请求字段（校验规则）：
     * - requestId：'required|uuid'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 为采集运行状态的业务结果
     * @see CollectorService::collectToday()
     */
    public function today(Request $request): JsonResponse
    {
        $validatedData = $request->validate(['requestId' => 'required|uuid']);

        return AppResponse::success($this->collectorService->collectToday(
            (int) $request->attributes->get('auth_user')->id,
            $validatedData['requestId'],
        ), httpStatus: 202);
    }
}
