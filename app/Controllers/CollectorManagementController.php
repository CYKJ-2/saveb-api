<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Services\CollectorManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollectorManagementController
{
    /**
     * 注入 采集任务处理所需的依赖。
     *
     * @param  CollectorManagementService  $collectorManagementService  采集任务业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private CollectorManagementService $collectorManagementService)
    {
    }

    /**
     * 读取自动采集间隔与下次执行时间。
     *
     * @return JsonResponse 统一 JSON 响应；data 包含intervalMinutes、nextRunAt、updatedAt、updatedBy 调度配置
     * @see CollectorManagementService::settings()
     */
    public function settings(): JsonResponse
    {
        return AppResponse::success($this->collectorManagementService->settings())->header('Cache-Control', 'no-store');
    }

    /**
     * 保存自动采集间隔并重新计算下次执行时间。
     *
     * 请求字段（校验规则）：
     * - intervalMinutes：'required|integer|min:5|max:1440'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 包含更新后的 intervalMinutes、nextRunAt、updatedAt、updatedBy
     * @see CollectorManagementService::saveSettings()
     */
    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['intervalMinutes' => 'required|integer|min:5|max:1440']);

        return AppResponse::success($this->collectorManagementService->saveSettings(
            $data['intervalMinutes'],
            (int) $request->attributes->get('auth_user')->id,
        ));
    }

    /**
     * 分页读取当前账户的采集任务列表。
     *
     * 分页字段：page 从 1 开始；per_page 为 1–100，默认 20。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 包含采集任务列表及相应分页信息
     * @see CollectorManagementService::jobs()
     */
    public function jobs(Request $request): JsonResponse
    {
        $data = $request->validate(\App\Common\PageResult::rules());

        return AppResponse::success($this->collectorManagementService->jobs($data['page'] ?? 1, $data['per_page'] ?? 20))->header('Cache-Control', 'no-store');
    }

    /**
     * 读取采集任务摘要及分页分片进度。
     *
     * 分页字段：page 从 1 开始；per_page 为 1–100，默认 20。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @param  string  $id  采集任务编号
     * @return JsonResponse 统一 JSON 响应；data 包含任务摘要及 chunks 分页分片结果
     * @see CollectorManagementService::detail()
     */
    public function detail(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(\App\Common\PageResult::rules());

        return AppResponse::success($this->collectorManagementService->detail($id, $data['page'] ?? 1, $data['per_page'] ?? 20))->header('Cache-Control', 'no-store');
    }

    /**
     * 校验日期范围并提交更新或补缺采集任务。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 为采集任务的业务结果
     */
    public function collect(Request $request): JsonResponse
    {
        return $this->submit($request, false);
    }

    /**
     * 提交已有归档任务的重算预览。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 为采集任务的业务结果
     */
    public function reprocess(Request $request): JsonResponse
    {
        return $this->submit($request, true);
    }

    /**
     * 提交采集任务并核对账户、模式和数据库发布状态。
     *
     * 请求字段（校验规则）：
     * - requestId：'required|uuid'
     * - mode：$reprocess ? 'required|in:reprocess' : 'required|in:history,missing'
     * - start：'required|date_format:Y-m-d|after_or_equal:2000-01-01'
     * - end：'required|date_format:Y-m-d|after_or_equal:start|before_or_equal:' . now('Asia/Shanghai')->toDateString()
     * - dryRun：'sometimes|boolean'
     * - sourceJobId：$reprocess ? 'required|uuid' : 'prohibited'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @param  bool  $reprocess  是否提交归档重算预览
     * @return JsonResponse 统一 JSON 响应；data 包含已核对入库的任务摘要，包括 jobId、mode、status 和进度
     * @see CollectorManagementService::submit()
     */
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
