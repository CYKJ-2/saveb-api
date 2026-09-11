<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Services\AnalysisImportService;
use App\Services\AnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** 采购成交价格分析接口：参数校验与统一响应，数据库操作在 Service / Dao 内完成。 */
class AnalysisController
{
    /**
     * 注入统计及只读副本导入服务。
     *
     * @param AnalysisService $analysisService 统计、明细及导出服务
     * @param AnalysisImportService $importService 导入和自动分类服务
     * @return void 初始化接口依赖
     */
    public function __construct(private AnalysisService $analysisService, private AnalysisImportService $importService)
    {
    }

    /**
     * 校验所有统计模块共用的筛选条件。
     *
     * @param Request $request 日期、品牌/品类主键、采购方式、客户类型、质量及分页参数
     * @return array 已校验参数；日期 YYYY-MM-DD，grain day/month，per_page 20/50/100
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'startDate' => 'nullable|date_format:Y-m-d',
            'endDate' => 'nullable|date_format:Y-m-d' . ($request->filled('startDate') ? '|after_or_equal:startDate' : ''),
            'category_id' => 'nullable|integer|min:1', 'brand_id' => 'nullable|integer|min:1', 'supplier_id' => 'nullable|integer|min:1',
            'source_period' => 'nullable|date_format:Y-m',
            'customer_key' => 'nullable|string|max:500',
            'customer_type' => 'nullable|in:unknown,first,returning',
            'purchase_method' => 'nullable|in:ws,pl,invoice,after_sale,influencer,accessory,unknown',
            'price_band' => 'nullable|in:negative,0-99.99,100-299.99,300-499.99,500-999.99,1000-2999.99,3000+',
            'keyword' => 'nullable|string|max:255',
            'scope' => 'nullable|in:eligible,all', 'quality' => 'nullable|in:needs_review,missing,classification',
            'grain' => 'nullable|in:day,month', 'locale' => 'nullable|in:zh-CN,en-US',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|in:20,50,100',
        ]);
    }

    /**
     * 获取筛选选项及中英文表头。
     *
     * @param Request $request locale 为 zh-CN 或 en-US
     * @return JsonResponse data 为字典、来源版本和列表/导出列定义
     */
    public function options(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return AppResponse::success($this->analysisService->options($filters['locale'] ?? 'zh-CN'));
    }

    /**
     * 查询成交汇总、逐日逐月趋势、排行占比及四类交叉分析。
     *
     * @param Request $request filters() 定义的统一条件；不传日期时为导入数据全部历史
     * @return JsonResponse data 包含 summary、trend、distributions、crosses，金额为 CNY 两位小数字符串
     */
    public function report(Request $request): JsonResponse
    {
        return AppResponse::success($this->analysisService->report($this->filters($request)));
    }

    /**
     * 分页读取分析明细。
     *
     * @param Request $request 统一筛选、当前语言、page 和 per_page（默认 20）
     * @return JsonResponse data 为 list、total、page、per_page、last_page
     */
    public function index(Request $request): JsonResponse
    {
        return AppResponse::success($this->analysisService->listing($this->filters($request)));
    }

    /**
     * 获取单行的原始单元格和分类依据。
     *
     * @param int $id 当前采购快照行主键
     * @return JsonResponse data 为原始行、匹配证据及来源文件、sheet、行号；不存在时 404
     */
    public function evidence(int $id): JsonResponse
    {
        return AppResponse::success($this->analysisService->evidence($id));
    }

    /**
     * 分页查询本地采集批次。
     *
     * @param Request $request page 和 per_page（默认 20）
     * @return JsonResponse data 包含文件名、有效版本标记、统计口径及导入时的质量汇总
     */
    public function imports(Request $request): JsonResponse
    {
        return AppResponse::success($this->analysisService->imports($this->filters($request)));
    }

    /**
     * 从 XLSX 副本采集采购当月或供应商字典；首次可显式导入全部历史。
     *
     * @param Request $request multipart file（≤256MB）、source_type（suppliers/procurement）、mode（current_month 默认／initialize 首次）；CNY 每行实际成交价格
     * @return JsonResponse data 包含批次 id、reused 及各 sheet 行数；失败时旧版本仍有效
     */
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => 'required|file|extensions:xlsx|max:262144', 'source_type' => 'required|in:suppliers,procurement',
            'mode' => 'sometimes|in:current_month,initialize',
        ]);
        $file = $request->file('file');

        return AppResponse::success($this->importService->import(
            $file->getRealPath(),
            $file->getClientOriginalName(),
            $data['source_type'],
            'CNY',
            'row_total',
            $request->attributes->get('auth_user')?->id,
            $data['mode'] ?? 'current_month',
        ));
    }

    /**
     * 分页查询客户名维度汇总。
     *
     * @param Request $request 统一业务筛选及 page、per_page
     * @return JsonResponse 客户金额、记录数、完整历史首次采购日期及分页
     */
    public function customers(Request $request): JsonResponse
    {
        return AppResponse::success($this->analysisService->customers($this->filters($request)));
    }

    /**
     * 导出全部筛选行，使用当前语言和页面相同的字段。
     *
     * @param Request $request 统一筛选和 locale；忽略当前分页位置
     * @return StreamedResponse 带 BOM 的 CSV，单元格公式前缀已转义
     */
    public function export(Request $request): StreamedResponse
    {
        return $this->analysisService->export($this->filters($request));
    }
}
