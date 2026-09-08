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
    public function __construct(private InfluencerService $influencerService)
    {
    }

    /**
     * 导出数据。
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
     * 查询目录。
     */
    public function directory(Request $request): JsonResponse
    {
        $validatedData = $request->validate(\App\Common\PageResult::rules() + ['keyword' => 'nullable|string|max:255']);

        return AppResponse::success($this->influencerService->directoryPage($validatedData));
    }

    public function options(): JsonResponse
    {
        return AppResponse::success($this->influencerService->options());
    }

    /**
     * 汇总销售业绩。
     */
    public function sales(Request $request): JsonResponse
    {
        return AppResponse::success($this->influencerService->salesPage($request->validate(\App\Common\PageResult::rules() + [
            'startDate' => 'required|date_format:Y-m-d',
            'endDate' => 'required|date_format:Y-m-d|after_or_equal:startDate',
        ])));
    }

    /** 达人销量月报。 */
    public function report(Request $request): JsonResponse
    {
        $data = $request->validate(\App\Common\PageResult::rules() + ['month' => 'required|date_format:Y-m']);

        return AppResponse::success($this->influencerService->reportPage($data));
    }

    /**
     * 保存记录。
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
