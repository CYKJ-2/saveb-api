<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Services\LogisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogisticsController
{
    /**
     * 注入 物流采集处理所需的依赖。
     *
     * @param  LogisticsService  $logisticsService  物流采集业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private LogisticsService $logisticsService)
    {
    }

    /**
     * 读取物流服务状态或指定物流任务的执行进度。
     *
     * 请求字段（校验规则）：
     * - jobId：'nullable|uuid'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 包含Collector 返回的物流服务或指定任务状态
     * @see LogisticsService::status()
     */
    public function status(Request $request): JsonResponse
    {
        $data = $request->validate(['jobId' => 'nullable|uuid']);

        return AppResponse::success($this->logisticsService->status($data['jobId'] ?? null))->header('Cache-Control', 'no-store');
    }

    /**
     * 提交物流刷新任务并记录操作人。
     *
     * 请求字段（校验规则）：
     * - requestId：'required|uuid'
     * - taskId：'nullable|integer|min:1'
     * - provider：'sometimes|in:auto,aftership,kuaidi100'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 包含Collector 已受理并经本地数据库核验的物流任务结果
     * @see LogisticsService::refresh()
     */
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
