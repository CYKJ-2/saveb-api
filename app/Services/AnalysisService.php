<?php

namespace App\Services;

use App\Dao\AnalysisDao;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** 基于采购明细的 CNY 成交分析和当前语言导出。 */
class AnalysisService
{
    private const TREND_START_DATE = '2026-07-01';

    public const COLUMNS = [
        'analysis_date' => ['发起采购日期', 'Procurement date'],
        'customer_order_date' => ['顾客下单日期', 'Customer order date'],
        'order_reference' => ['下单形式／单号', 'Order form / reference'],
        'purchase_method' => ['采购方式', 'Purchase method'],
        'customer_name' => ['客户名', 'Customer'],
        'customer_type' => ['顾客类型', 'Customer type'],
        'customer_first_date' => ['首次采购日（已知历史）', 'First purchase (known history)'],
        'brand_raw' => ['原始品牌代号', 'Source brand code'],
        'brand' => ['品牌', 'Brand'], 'category' => ['品类', 'Category'],
        'product_description' => ['货号', 'Item description'], 'supplier_name' => ['供应商', 'Supplier'],
        'supplier_quote' => ['供应商定价', 'Supplier quoted price'],
        'actual_price' => ['实际成交价格', 'Actual transaction price'],
        'analysis_amount' => ['纳入统计金额', 'Eligible amount'],
        'currency' => ['币种', 'Currency'], 'purchase_status' => ['是否采购', 'Purchase status'],
        'price_raw' => ['原始价格文本', 'Source price text'],
        'price_status' => ['价格状态', 'Price status'],
        'brand_match_status' => ['品牌匹配', 'Brand matching'],
        'classification_status' => ['品类匹配', 'Category matching'],
        'issues_text' => ['数据核查提示', 'Data quality notes'],
        'source_period' => ['来源月份', 'Source month'],
        'sheet_name' => ['来源 Sheet', 'Source worksheet'], 'row_number' => ['来源行号', 'Source row'],
    ];

    private const LABELS = [
        'unknown' => ['未知', 'Unknown'], 'matched' => ['已匹配', 'Matched'],
        'unmatched' => ['未匹配', 'Unmatched'], 'ambiguous' => ['多个候选', 'Ambiguous'],
        'pending' => ['待确认', 'Pending confirmation'], 'conflict' => ['信息冲突', 'Conflicting evidence'],
        'first' => ['首购', 'First purchase'], 'returning' => ['复购', 'Repeat purchase'],
        'ws' => ['WS', 'WS'], 'pl' => ['PL', 'PL'], 'invoice' => ['Invoice', 'Invoice'],
        'after_sale' => ['售后／换补货', 'After-sales / replacements'],
        'influencer' => ['达人／样品', 'Creator / sample'], 'accessory' => ['配件', 'Accessories'],
        'valid' => ['有效', 'Valid'], 'missing' => ['缺失', 'Missing'], 'invalid' => ['无效', 'Invalid'],
        'currency_conflict' => ['币种冲突', 'Currency conflict'],
    ];

    private const ISSUES = [
        'price_missing' => ['缺少实际成交价格', 'Missing actual price'],
        'price_invalid' => ['价格无效／公式错误', 'Invalid price / formula error'],
        'price_currency_conflict' => ['价格币种不是 CNY', 'Price currency differs from CNY'],
        'procurement_date_missing' => ['缺少有效采购日期', 'Missing valid procurement date'],
        'customer_missing' => ['缺少客户名', 'Missing customer'],
        'brand_code_missing' => ['缺少品牌代号', 'Missing brand code'],
        'supplier_missing' => ['缺少供应商', 'Missing supplier'],
        'date_outside_sheet' => ['采购日期不在来源月份', 'Date outside source month'],
        'legacy_price_column' => ['使用旧表“价格”列', 'Legacy Price column used'],
    ];

    /**
     * 注入 SQL 聚合仓储。
     *
     * @param AnalysisDao $analysisDao 采购统计及分页查询
     * @return void 完成依赖初始化
     */
    public function __construct(private AnalysisDao $analysisDao)
    {
    }

    /**
     * 返回中英文字典和页面／导出共用表头。
     *
     * @param string $locale zh-CN 或 en-US
     * @return array 当前有效批次、月份、字典、列定义
     */
    public function options(string $locale): array
    {
        $index = $locale === 'en-US' ? 1 : 0;
        $columns = [];
        foreach (self::COLUMNS as $key => $names) {
            $columns[] = ['key' => $key, 'label' => $names[$index]];
        }

        return $this->analysisDao->options() + ['columns' => $columns];
    }

    /**
     * 从同一数据库快照返回概览、趋势、排行占比和交叉分析。
     *
     * @param array $filters 日期及品牌、品类、采购方式等筛选
     * @return array summary、trend、distributions、crosses，币种固定 CNY
     */
    public function report(array $filters): array
    {
        return DB::transaction(function () use ($filters): array {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $summary = $this->analysisDao->summary($filters);
            $distributions = [];
            foreach (array_keys(AnalysisDao::DIMENSIONS) as $dimension) {
                $distributions[$dimension] = $this->analysisDao->distribution($filters, $dimension);
            }
            $crosses = [];
            foreach (['brand_category' => ['brand', 'category'], 'category_price' => ['category', 'price_band'],
                'customer_brand' => ['customer_type', 'brand'], 'customer_category' => ['customer_type', 'category']] as $key => [$left, $right]) {
                $crosses[$key] = $this->analysisDao->cross($filters, $left, $right);
            }

            return ['summary' => $summary, 'trend' => $this->trend($filters, $summary),
                'distributions' => $distributions, 'crosses' => $crosses, 'currency' => 'CNY',
                'metric_basis' => 'purchased_procurement_actual_price', 'customer_basis' => 'normalized_name_first_procurement_day'];
        });
    }

    /**
     * 趋势按自然月／年展开，仅统计 2026 年 7 月起的成交金额。
     *
     * @param array $filters 已查询日期及 grain；仅趋势扩展日期，品牌等筛选原样保留
     * @param array $summary 有效成交日期边界
     * @return array grain、startDate、endDate、periods 及 points；范围早于起算日时保留原日期边界并返回空序列，概览仍使用原始日期
     */
    private function trend(array $filters, array $summary): array
    {
        $grain = $filters['grain'] ?? 'month';
        $today = CarbonImmutable::now('Asia/Shanghai')->toDateString();
        $start = CarbonImmutable::parse($filters['startDate'] ?? $summary['first_date'] ?? $filters['endDate'] ?? $today);
        $end = CarbonImmutable::parse($filters['endDate'] ?? $summary['last_date'] ?? $filters['startDate'] ?? $today);
        $start = $grain === 'month' ? $start->startOfYear() : $start->startOfMonth();
        $end = $grain === 'month' ? $end->endOfYear() : $end->endOfMonth();
        if ($start->diffInDays($end) > 20000) {
            throw ValidationException::withMessages(['startDate' => '日期范围不能超过 20,000 天。']);
        }
        $earliestDate = CarbonImmutable::parse(self::TREND_START_DATE);
        if ($end->lessThan($earliestDate)) {
            return [
                'grain' => $grain,
                'startDate' => $start->toDateString(),
                'endDate' => $end->toDateString(),
                'periods' => [],
                'points' => [],
            ];
        }
        $start = $start->max($earliestDate);
        $trendFilters = array_replace($filters, ['startDate' => $start->toDateString(), 'endDate' => $end->toDateString()]);
        $periods = [];
        for ($cursor = $start; $cursor <= $end; $cursor = $grain === 'day' ? $cursor->addDay() : $cursor->addMonth()) {
            $periods[] = $cursor->format($grain === 'day' ? 'Y-m-d' : 'Y-m');
        }

        return ['grain' => $grain, 'startDate' => $start->toDateString(), 'endDate' => $end->toDateString(),
            'periods' => $periods, 'points' => $this->analysisDao->trend($trendFilters, $grain)];
    }

    /**
     * 分页展示成交明细或缺失／分类核查行。
     *
     * @param array $filters 统一筛选、scope、quality、locale 及分页
     * @return array 当前页 list 和分页元数据
     */
    public function listing(array $filters): array
    {
        $page = $this->analysisDao->listing($filters);
        $rows = array_map(fn (object $row): array => $this->present($row, $filters['locale'] ?? 'zh-CN'), $page->items());

        return $this->page($page, $rows);
    }

    /**
     * 返回按客户名汇总的明细分页。
     *
     * @param array $filters 统一筛选和分页条件
     * @return array 客户金额、首购日、复购金额及分页元数据
     */
    public function customers(array $filters): array
    {
        $page = $this->analysisDao->customers($filters);

        return $this->page($page, $page->items());
    }

    /**
     * 返回上传历史和操作者名称。
     *
     * @param array $filters page 和 per_page
     * @return array 当前页导入批次及有效月份
     */
    public function imports(array $filters): array
    {
        $page = $this->analysisDao->imports($filters);

        return $this->page($page, $page->items());
    }

    /**
     * 统一分页结构。
     *
     * @param LengthAwarePaginator $page SQL 分页结果
     * @param array $rows 格式化后的本页行
     * @return array list、total、page、per_page、last_page
     */
    private function page(LengthAwarePaginator $page, array $rows): array
    {
        return ['list' => $rows, 'total' => $page->total(), 'page' => $page->currentPage(),
            'per_page' => $page->perPage(), 'last_page' => $page->lastPage()];
    }

    /**
     * 查询单行原始单元格、公式及匹配依据。
     *
     * @param int $id 有效明细主键
     * @return array 来源文件、行号、原始值和分类证据，不存在返回 404
     */
    public function evidence(int $id): array
    {
        $record = $this->analysisDao->evidence($id);
        abort_unless($record, 404);
        $data = (array) $record;
        foreach (['raw', 'classification_evidence', 'issues'] as $field) {
            $data[$field] = json_decode($data[$field] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        }

        return $data;
    }

    /**
     * 统一页面和导出的字段、枚举及两位金额文字。
     *
     * @param object $record 数据库采购明细
     * @param string $locale 当前语言
     * @return array 原始字段和 display 显示字段
     */
    private function present(object $record, string $locale): array
    {
        $row = (array) $record;
        $index = $locale === 'en-US' ? 1 : 0;
        $row['brand'] = ($index ? $row['brand_name_en'] : $row['brand_name']) ?: ($index ? 'Unclassified' : '待分类');
        $row['category'] = ($index ? $row['category_name_en'] : $row['category_name']) ?: ($index ? 'Unclassified' : '待分类');
        $issues = json_decode($row['issues'] ?? '[]', true) ?: [];
        $row['issues_text'] = implode('；', array_map(static fn (string $issue): string => self::ISSUES[$issue][$index] ?? $issue, $issues));
        $display = [];
        foreach (array_keys(self::COLUMNS) as $field) {
            $value = $row[$field] ?? null;
            $display[$field] = in_array($field, ['customer_type', 'purchase_method', 'price_status', 'brand_match_status', 'classification_status'], true)
                ? (self::LABELS[$value ?? 'unknown'][$index] ?? $value) : ($value ?? '—');
        }
        // 私有单元格详情单独按主键查询，列表不重复传输整行 JSON。
        unset($row['raw'], $row['classification_evidence']);

        return $row + ['display' => $display];
    }

    /**
     * 导出所有筛选记录，列名及显示值与当前语言的页面一致。
     *
     * @param array $filters 与列表一致的筛选，忽略分页
     * @return StreamedResponse UTF-8 BOM CSV，分块读取且防止公式注入
     */
    public function export(array $filters): StreamedResponse
    {
        $locale = $filters['locale'] ?? 'zh-CN';
        $rows = $this->analysisDao->exportRows($filters);

        return response()->streamDownload(function () use ($rows, $locale): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, array_column(self::COLUMNS, $locale === 'en-US' ? 1 : 0), ',', '"', '');
            foreach ($rows as $row) {
                $display = $this->present($row, $locale)['display'];
                $cells = [];
                foreach (array_keys(self::COLUMNS) as $field) {
                    $value = (string) $display[$field];
                    $numeric = in_array($field, ['supplier_quote', 'actual_price', 'analysis_amount', 'row_number'], true) && preg_match('/^-?\d+(\.\d+)?$/D', $value);
                    $cells[] = !$numeric && preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'" . $value : $value;
                }
                fputcsv($stream, $cells, ',', '"', '');
            }
            fclose($stream);
        }, 'analysis-CNY-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
