<?php

namespace App\Services;

use App\Dao\AnalysisDao;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Analysis 展示口径：只汇总采购表实际成交价格，保留未知值及来源覆盖情况。 */
class AnalysisService
{
    /** @var array<string, array<int, string>> 页面和导出共用的列顺序及中英文名称。 */
    public const COLUMNS = [
        'analysis_date' => ['统计日期', 'Analysis date'],
        'date_basis' => ['日期依据', 'Date basis'],
        'order_reference' => ['原表订单号', 'Source order reference'],
        'record_type' => ['采购类型', 'Procurement type'],
        'customer_name' => ['客户名', 'Customer'],
        'brand' => ['品牌', 'Brand'],
        'category' => ['品类', 'Category'],
        'product_description' => ['货号 / 产品描述', 'Item / product description'],
        'supplier_raw' => ['供应商', 'Supplier'],
        'actual_price' => ['原表成交价格', 'Source transaction price'],
        'quantity' => ['数量', 'Quantity'],
        'analysis_amount' => ['统计金额', 'Analysis amount'],
        'currency' => ['币种', 'Currency'],
        'price_basis' => ['价格口径', 'Price basis'],
        'country' => ['国家', 'Country'],
        'customer_type' => ['顾客类型（已知历史）', 'Customer type (known history)'],
        'classification_status' => ['品类匹配', 'Category matching'],
        'order_match_status' => ['订单匹配', 'Order matching'],
        'purchase_status' => ['采购状态', 'Purchase status'],
        'sheet_name' => ['来源工作表', 'Source worksheet'],
        'row_number' => ['来源行号', 'Source row'],
    ];

    /** @var array<string, array<int, string>> 派生字段值的中英文显示文本。 */
    private const LABELS = [
        'unknown' => ['未知', 'Unknown'], 'matched' => ['已匹配', 'Matched'],
        'unmatched' => ['未匹配', 'Unmatched'], 'ambiguous' => ['多个候选', 'Ambiguous'],
        'conflict' => ['信息冲突', 'Conflicting evidence'], 'needs_evidence' => ['缺少核对依据', 'Needs corroboration'],
        'first' => ['已知历史首次购物', 'First in known history'], 'returning' => ['复购顾客', 'Returning customer'],
        'ordinary' => ['普通订单采购', 'Regular order procurement'], 'invoice' => ['Invoice 订单采购', 'Invoice procurement'],
        'after_sale' => ['售后 / 换补货', 'After-sales / replacements'], 'other_procurement' => ['达人 / 样品等采购', 'Creator / sample procurement'],
        'row_total' => ['每行合计', 'Row total'], 'unit' => ['单件价格', 'Unit price'],
        'customer_order_date' => ['顾客下单日期', 'Customer order date'], 'procurement_date' => ['发起采购日期（补充）', 'Procurement date (fallback)'],
        'legacy_date' => ['原表日期（补充）', 'Legacy date (fallback)'],
    ];

    /**
     * 注入数据库聚合和分页依赖。
     *
     * @param AnalysisDao $analysisDao 采购快照查询仓储
     * @return void 完成依赖初始化
     */
    public function __construct(private AnalysisDao $analysisDao)
    {
    }

    /**
     * 返回筛选项、当前导入来源以及列表/导出共用列定义。
     *
     * @param string $locale zh-CN 或 en-US
     * @return array 字典、当前批次与当前语言列定义
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
     * 一次请求返回各统计模块，减少页面独立请求开销；明细仍单独分页。
     *
     * @param array $filters 日期、维度筛选及 grain（day/month）
     * @return array 质量摘要、分币种金额、趋势和六个维度的分布
     */
    public function report(array $filters): array
    {
        $summary = $this->analysisDao->summary($filters);
        $totals = $this->analysisDao->totals($filters);
        $distributions = [];
        foreach (array_keys(AnalysisDao::DIMENSIONS) as $dimension) {
            $distributions[$dimension] = $this->analysisDao->distribution($filters, $dimension);
        }

        return [
            'summary' => $summary, 'totals' => $totals,
            'trend' => $this->trend($filters, $summary, $totals),
            'distributions' => $distributions,
            'metric_basis' => 'procurement_actual_transaction_price',
        ];
    }

    /**
     * 填补选择区间内缺记录的日期；空档金额为 null，避免伪造完整销售历史。
     *
     * @param array $filters 日期范围及 grain
     * @param array $summary 当前筛选的实际日期边界
     * @param array $totals 当前筛选使用的币种
     * @return array 包含完整横轴 periods、数据库 points 和 grain 的趋势
     */
    private function trend(array $filters, array $summary, array $totals): array
    {
        $grain = $filters['grain'] ?? 'month';
        $start = $filters['startDate'] ?? $summary['first_date'];
        $end = $filters['endDate'] ?? $summary['last_date'];
        $periods = [];
        if ($start && $end) {
            $cursor = CarbonImmutable::parse($start);
            $last = CarbonImmutable::parse($end);
            if ($grain === 'month') {
                $cursor = $cursor->startOfMonth();
                $last = $last->startOfMonth();
            }
            if ($cursor->diffInDays($last) > 20000) {
                throw ValidationException::withMessages(['startDate' => 'The date range exceeds 20,000 days.']);
            }
            while ($cursor <= $last) {
                $periods[] = $cursor->format($grain === 'day' ? 'Y-m-d' : 'Y-m');
                $cursor = $grain === 'day' ? $cursor->addDay() : $cursor->addMonth();
            }
        }

        return ['grain' => $grain, 'periods' => $periods, 'currencies' => array_column($totals, 'currency'), 'points' => $this->analysisDao->trend($filters, $grain)];
    }

    /**
     * 返回当前页明细，金额保持两位小数字符串。
     *
     * @param array $filters 筛选条件和分页参数，locale 控制派生文字
     * @return array list、total、page、per_page、last_page
     */
    public function listing(array $filters): array
    {
        $page = $this->analysisDao->listing($filters);
        $rows = array_map(fn (object $row): array => $this->present($row, $filters['locale'] ?? 'zh-CN'), $page->items());

        return $this->page($page, $rows);
    }

    /**
     * 返回分页采集历史。
     *
     * @param array $filters page 和 per_page
     * @return array 导入版本列表及分页元数据
     */
    public function imports(array $filters): array
    {
        $page = $this->analysisDao->imports($filters);

        return $this->page($page, $page->items());
    }

    /**
     * 整理统一分页响应结构。
     *
     * @param LengthAwarePaginator $page 已执行的数据库分页结果
     * @param array $rows 已转换的当前页数据
     * @return array 前端统一分页结构
     */
    private function page(LengthAwarePaginator $page, array $rows): array
    {
        return ['list' => $rows, 'total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()];
    }

    /**
     * 读取原始行与匹配依据，供待确认记录核查。
     *
     * @param int $id 当前采购记录主键
     * @return array 原始单元格、公式缓存来源与匹配证据；不存在时返回 404
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
     * 将派生枚举和字典转换成当前语言，供列表和导出共用。
     *
     * @param object $record 数据库明细投影
     * @param string $locale 当前界面语言
     * @return array 带原始字段和 display 显示值的明细
     */
    private function present(object $record, string $locale): array
    {
        $row = (array) $record;
        $index = $locale === 'en-US' ? 1 : 0;
        $row['brand'] = ($index ? $row['brand_en'] : $row['brand_zh']) ?: $row['brand_raw'];
        $row['category'] = $index ? $row['category_en'] : $row['category_zh'];
        $display = [];
        foreach (array_keys(self::COLUMNS) as $field) {
            $value = $row[$field] ?? null;
            $display[$field] = in_array($field, ['date_basis', 'record_type', 'price_basis', 'customer_type', 'classification_status', 'order_match_status'], true)
                ? (self::LABELS[$value ?? 'unknown'][$index] ?? $value)
                : ($value ?? '—');
        }

        return $row + ['display' => $display];
    }

    /**
     * 导出当前全部筛选结果，字段、顺序和显示文字与页面完全一致。
     *
     * @param array $filters 统一筛选条件；locale 为导出语言
     * @return StreamedResponse UTF-8 BOM CSV；逐块读取，避免一次加载全表
     */
    public function export(array $filters): StreamedResponse
    {
        $locale = $filters['locale'] ?? 'zh-CN';

        return response()->streamDownload(function () use ($filters, $locale): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, array_column(self::COLUMNS, $locale === 'en-US' ? 1 : 0), ',', '"', '');
            foreach ($this->analysisDao->exportRows($filters) as $row) {
                $display = $this->present($row, $locale)['display'];
                $cells = [];
                foreach (array_keys(self::COLUMNS) as $field) {
                    $value = (string) $display[$field];
                    $numeric = in_array($field, ['actual_price', 'quantity', 'analysis_amount', 'row_number'], true) && preg_match('/^-?\d+(\.\d+)?$/D', $value);
                    $cells[] = !$numeric && preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'" . $value : $value;
                }
                fputcsv($stream, $cells, ',', '"', '');
            }
            fclose($stream);
        }, 'analysis-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
