<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Services\OperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 工作巡查接口：负责参数校验、调用服务和封装响应。
 */
class OperationsController
{
    /**
     * 注入 在线表格处理所需的依赖。
     *
     * @param  OperationsService  $operationsService  在线表格业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private OperationsService $operationsService)
    {
    }

    /**
     * 查询在线表格列表。
     *
     * 请求字段（校验规则）：
     * - keyword：'nullable|string|max:255'
     * - department：'nullable|string|max:100'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 包含线上表格目录的当前页及部门汇总信息
     * @see OperationsService::directory()
     */
    public function index(Request $request): JsonResponse
    {
        return AppResponse::success($this->operationsService->directory($request->validate([
            'keyword' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:100',
        ])));
    }

    /**
     * 查询在线表格目录及固定部门数量，用于工作巡查分组展示。
     *
     * 请求字段（校验规则）：
     * - keyword：'nullable|string|max:255'
     * - department：'nullable|string|max:100'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 为在线表格的业务结果
     * @see OperationsService::report()
     */
    public function directory(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:100',
        ]);

        return AppResponse::success($this->operationsService->report($filters));
    }
}
