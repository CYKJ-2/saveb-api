<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Services\InfluencerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 达人接口：负责参数校验、调用服务和封装响应。
 */
class InfluencerController
{
    /**
     * 注入 达人与网站处理所需的依赖。
     *
     * @param  InfluencerService  $influencerService  达人与网站业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private InfluencerService $influencerService)
    {
    }

    /**
     * 导出达人与网站的完整筛选结果。
     *
     * @return StreamedResponse CSV 流式下载响应，包含表头及导出数据
     * @see InfluencerService::directory()
     */
    public function export(): StreamedResponse
    {
        $rows = [];
        foreach ($this->influencerService->directory() as $group) {
            foreach ($group['domains'] as $domain) {
                $rows[] = [
                    'name' => $group['name'],
                    'domain' => $domain['domain'],
                    'confirmed' => $domain['confirmed'],
                ];
            }
        }

        return \App\Common\CsvResponse::download($rows, [
            'name' => 'Influencer',
            'domain' => 'Website',
            'confirmed' => 'Confirmed',
        ], 'influencers.csv');
    }

    /**
     * 查询达人与网站目录。
     *
     * 分页字段：page 从 1 开始；per_page 为 1–100，默认 20。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 为达人与网站的业务结果
     * @see InfluencerService::directoryPage()
     */
    public function directory(Request $request): JsonResponse
    {
        $validatedData = $request->validate(\App\Common\PageResult::rules() + ['keyword' => 'nullable|string|max:255']);

        return AppResponse::success($this->influencerService->directoryPage($validatedData));
    }

    /**
     * 读取新增达人网站表单所需的达人名称选项。
     *
     * @return JsonResponse 统一 JSON 响应；data 包含可用于网站绑定的达人名称列表
     * @see InfluencerService::options()
     */
    public function options(): JsonResponse
    {
        return AppResponse::success($this->influencerService->options());
    }

    /**
     * 汇总销售业绩。
     *
     * 分页字段：page 从 1 开始；per_page 为 1–100，默认 20。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 为达人与网站的业务结果
     * @see InfluencerService::salesPage()
     */
    public function sales(Request $request): JsonResponse
    {
        return AppResponse::success($this->influencerService->salesPage($request->validate(\App\Common\PageResult::rules() + [
            'startDate' => 'required|date_format:Y-m-d',
            'endDate' => 'required|date_format:Y-m-d|after_or_equal:startDate',
        ])));
    }

    /**
     * 达人销量月报。
     *
     * 分页字段：page 从 1 开始；per_page 为 1–100，默认 20。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 为达人与网站的统计报表
     * @see InfluencerService::reportPage()
     */
    public function report(Request $request): JsonResponse
    {
        $data = $request->validate(\App\Common\PageResult::rules() + ['month' => 'required|date_format:Y-m']);

        return AppResponse::success($this->influencerService->reportPage($data));
    }

    /**
     * 保存达人与网站及其关联数据。
     *
     * 请求字段（校验规则）：
     * - domain：'required|string|max:255'
     * - influencer：'required|string|max:255'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 包含保存后的达人网站归属记录
     * @see InfluencerService::save()
     */
    public function save(Request $request): JsonResponse
    {
        return AppResponse::success($this->influencerService->save(
            $request->validate([
                'domain' => 'required|string|max:255',
                'influencer' => 'required|string|max:255',
            ]),
            (int) $request->attributes->get('auth_user')->id,
        ));
    }
}
