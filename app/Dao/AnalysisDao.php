<?php

namespace App\Dao;

use App\Models\AnalysisBrand;
use App\Models\AnalysisCategory;
use App\Models\AnalysisImport;
use App\Models\AnalysisProcurementRow;
use App\Models\AnalysisSupplier;
use App\Models\AnalysisSupplierRule;
use App\Models\InvoiceOrder;
use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Analysis 的批次持久化和数据库聚合；列表分页始终在 SQL 中完成。 */
class AnalysisDao
{
    /** @var array<string, string> 允许聚合的维度与固定 SQL 表达式，不接受用户输入作为 SQL。 */
    public const DIMENSIONS = [
        'category' => "COALESCE(categories.code, 'unknown')",
        'brand' => "COALESCE(brands.name_en, 'unknown')",
        'supplier' => "COALESCE(suppliers.name, NULLIF(records.supplier_raw, ''), 'unknown')",
        'country' => "COALESCE(NULLIF(records.country, ''), 'unknown')",
        'customer_type' => 'records.customer_type',
        'price_band' => "CASE WHEN records.actual_price IS NULL OR records.price_status != 'valid' THEN 'unknown' WHEN records.actual_price < 0 THEN 'negative' WHEN records.actual_price < 100 THEN '0–99.99' WHEN records.actual_price < 300 THEN '100–299.99' WHEN records.actual_price < 500 THEN '300–499.99' WHEN records.actual_price < 1000 THEN '500–999.99' WHEN records.actual_price < 3000 THEN '1000–2999.99' ELSE '3000+' END",
    ];

    /**
     * 汇总当前筛选的记录和数据覆盖率；未确认金额不按零计入。
     *
     * @param array $filters 统一日期、币种及业务筛选条件
     * @return array 记录数、有效金额行数、分类/品牌/订单/客户覆盖数及日期范围
     */
    public function summary(array $filters): array
    {
        return (array) $this->rowsQuery($filters)->selectRaw("COUNT(*) AS rows,
            COUNT(records.analysis_amount) AS amount_rows,
            COUNT(records.category_id) AS category_rows, COUNT(records.brand_id) AS brand_rows,
            COUNT(*) FILTER (WHERE records.order_match_status = 'matched') AS linked_rows,
            COUNT(records.country) AS country_rows,
            COUNT(*) FILTER (WHERE records.customer_type != 'unknown') AS customer_rows,
            COUNT(*) FILTER (WHERE records.date_basis != 'customer_order_date') AS fallback_date_rows,
            COUNT(*) FILTER (WHERE records.is_cancelled) AS cancelled_rows,
            COUNT(DISTINCT records.linked_order_type || ':' || records.linked_order_id) AS linked_orders,
            MIN(records.analysis_date) AS first_date, MAX(records.analysis_date) AS last_date")->first();
    }

    /**
     * 分币种汇总金额，不将人民币和美元混加。
     *
     * @param array $filters 统一筛选条件
     * @return array<int, array> 币种、记录数、有效金额行数与两位小数金额；无有效金额时为 null
     */
    public function totals(array $filters): array
    {
        return $this->rowsQuery($filters)->select('records.currency')
            ->selectRaw('COUNT(*) AS rows, COUNT(records.analysis_amount) AS amount_rows, ROUND(SUM(records.analysis_amount), 2)::text AS amount')
            ->groupBy('records.currency')->orderBy('records.currency')->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    /**
     * 在数据库中按日或月、币种汇总趋势；不返回原始明细。
     *
     * @param array $filters 统一筛选条件
     * @param string $grain day 或 month
     * @return array<int, array> 含 period、currency、rows、amount_rows、amount 的趋势点
     */
    public function trend(array $filters, string $grain): array
    {
        $period = $grain === 'day' ? 'records.analysis_date::text' : "to_char(records.analysis_date, 'YYYY-MM')";

        return $this->rowsQuery($filters)->whereNotNull('records.analysis_date')
            ->selectRaw($period . ' AS period, records.currency, COUNT(*) AS rows, COUNT(records.analysis_amount) AS amount_rows, ROUND(SUM(records.analysis_amount), 2)::text AS amount')
            ->groupByRaw($period . ', records.currency')->orderBy('period')->orderBy('records.currency')
            ->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    /**
     * 按白名单维度和币种统计分布，保留未知分组用于检查覆盖率。
     *
     * @param array $filters 统一筛选条件
     * @param string $dimension DIMENSIONS 中的固定维度名称
     * @return array<int, array> 分组 key、币种、记录数、有效金额行数及金额
     */
    public function distribution(array $filters, string $dimension): array
    {
        $expression = self::DIMENSIONS[$dimension];

        return $this->rowsQuery($filters)->selectRaw($expression . ' AS key, records.currency, COUNT(*) AS rows, COUNT(records.analysis_amount) AS amount_rows, ROUND(SUM(records.analysis_amount), 2)::text AS amount')
            ->groupByRaw($expression . ', records.currency')->orderByRaw('SUM(records.analysis_amount) DESC NULLS LAST')->orderBy('key')
            ->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    /**
     * 读取当前采购行实际使用的筛选项及两个当前来源版本。
     *
     * @return array 品类、品牌、币种、国家、sheet 和当前批次，避免无关历史字典进入筛选
     */
    public function options(): array
    {
        $query = $this->rowsQuery(['include_cancelled' => true]);

        return [
            'categories' => AnalysisCategory::orderBy('id')->get(['id', 'code', 'name_zh', 'name_en']),
            'brands' => (clone $query)->whereNotNull('records.brand_id')->select('brands.id', 'brands.name_zh', 'brands.name_en')->distinct()->orderBy('brands.name_en')->get(),
            'currencies' => (clone $query)->whereNotNull('records.currency')->distinct()->orderBy('records.currency')->pluck('records.currency'),
            'countries' => (clone $query)->whereNotNull('records.country')->distinct()->orderBy('records.country')->pluck('records.country'),
            'sheets' => (clone $query)->distinct()->orderBy('records.sheet_name')->pluck('records.sheet_name'),
            'imports' => $this->activeImports(),
        ];
    }

    /**
     * 在 SQL 中分页读取采购明细，默认每页 20 行。
     *
     * @param array $filters 统一筛选、page 和 per_page 参数
     * @return LengthAwarePaginator 当前页明细及总数
     */
    public function listing(array $filters): LengthAwarePaginator
    {
        return $this->detailsQuery($filters)->orderByDesc('records.analysis_date')->orderByDesc('records.id')
            ->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
    }

    /**
     * 流式遍历全部筛选结果供导出，不受当前页限制。
     *
     * @param array $filters 与列表完全相同的筛选条件
     * @return \Illuminate\Support\LazyCollection 分块读取的结果集合，每块 500 行
     */
    public function exportRows(array $filters): \Illuminate\Support\LazyCollection
    {
        // 固定开始导出时的采购版本，避免中途导入新文件造成导出断页或混用两个版本。
        $filters['snapshot_import_id'] = $this->activeImports()->firstWhere('source_type', 'procurement')?->id ?? 0;

        return $this->detailsQuery($filters)->orderByDesc('records.analysis_date')->orderByDesc('records.id')->lazy(500);
    }

    /**
     * 读取当前行的原始来源和自动匹配证据。
     *
     * @param int $id 当前批次采购行主键
     * @return object|null 原始行与证据；旧版本或不存在时返回 null
     */
    public function evidence(int $id): ?object
    {
        return $this->rowsQuery(['include_cancelled' => true])->where('records.id', $id)
            ->select('records.raw', 'records.classification_evidence', 'records.issues', 'imports.filename', 'imports.source_url', 'records.sheet_name', 'records.row_number')->first();
    }

    /**
     * 分页显示导入历史，保留旧版本和导入时的质量统计。
     *
     * @param array $filters page 和 per_page 参数
     * @return LengthAwarePaginator 包含有效状态的导入历史
     */
    public function imports(array $filters): LengthAwarePaginator
    {
        return AnalysisImport::orderByDesc('id')->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
    }

    /**
     * 获取相同文件及统计口径的已有导入批次。
     *
     * @param string $signature 文件摘要和币种、价格口径组合的 SHA-256
     * @return AnalysisImport|null 已存在的批次或 null
     */
    public function findImport(string $signature): ?AnalysisImport
    {
        return AnalysisImport::where('signature', $signature)->first();
    }

    /**
     * 建立尚未激活的导入批次。
     *
     * @param array $attributes 已校验的文件来源、口径及操作者信息
     * @return AnalysisImport 新批次模型
     */
    public function createImport(array $attributes): AnalysisImport
    {
        return AnalysisImport::create($attributes);
    }

    /**
     * 取得或创建品类，保留固定的中英文显示名称。
     *
     * @param string $code 商品品类 code
     * @param array<int, string> $names 中文和英文名称
     * @return int 品类主键
     */
    public function category(string $code, array $names): int
    {
        return AnalysisCategory::firstOrCreate(['code' => $code], ['name_zh' => $names[0], 'name_en' => $names[1]])->id;
    }

    /**
     * 按完整品牌名称取得品牌，不使用可能冲突的来源代号作为唯一键。
     *
     * @param string $identity 完整品牌名称规范化后的 SHA-256
     * @param string $nameZh 原表中文名称
     * @param string $nameEn 原表英文名称
     * @return int 品牌主键
     */
    public function brand(string $identity, string $nameZh, string $nameEn): int
    {
        return AnalysisBrand::firstOrCreate(['identity' => $identity], ['name_zh' => $nameZh, 'name_en' => $nameEn])->id;
    }

    /**
     * 按精确规范化名称取得供应商。
     *
     * @param string $identity 规范化供应商名称的 SHA-256
     * @param string $name 来源显示名称
     * @return int 供应商主键
     */
    public function supplier(string $identity, string $name): int
    {
        return AnalysisSupplier::firstOrCreate(['identity' => $identity], ['name' => $name])->id;
    }

    /**
     * 批量写入供应商对应关系。
     *
     * @param array<int, array> $rules 包含原始行 JSON 字符串的规则记录
     * @return void 无返回值；调用方负责事务
     */
    public function insertRules(array $rules): void
    {
        foreach (array_chunk($rules, 300) as $chunk) {
            AnalysisSupplierRule::insert($chunk);
        }
    }

    /**
     * 批量写入采购商品行，不按内容去重，以免合并合法的重复商品。
     *
     * @param array<int, array> $rows 每行有明确来源 sheet 和行号的已序列化记录
     * @return void 无返回值；同一批次的来源行号受唯一约束保护
     */
    public function insertRows(array $rows): void
    {
        foreach (array_chunk($rows, 300) as $chunk) {
            AnalysisProcurementRow::insert($chunk);
        }
    }

    /**
     * 在同一事务中切换该类来源的有效版本。
     *
     * @param AnalysisImport $import 已完整写入的批次
     * @param array $summary 导入行数、各 sheet 数量与质量汇总
     * @return void 无返回值；旧批次及其记录仍保留
     */
    public function activate(AnalysisImport $import, array $summary): void
    {
        AnalysisImport::where('source_type', $import->source_type)->where('is_active', true)->update(['is_active' => false]);
        $import->update(['is_active' => true, 'summary' => $summary]);
    }

    /**
     * 读取当前供应商规则和显示字典，一次加载供导入过程复用。
     *
     * @return array<int, array> 当前规则，包括供应商、品牌、品类与来源位置
     */
    public function activeRules(): array
    {
        return DB::table('analysis_supplier_rules as rules')
            ->join('analysis_imports as imports', 'imports.id', '=', 'rules.import_id')
            ->join('analysis_categories as categories', 'categories.id', '=', 'rules.category_id')
            ->leftJoin('analysis_suppliers as suppliers', 'suppliers.id', '=', 'rules.supplier_id')
            ->leftJoin('analysis_brands as brands', 'brands.id', '=', 'rules.brand_id')
            ->where('imports.is_active', true)
            ->select('rules.*', 'categories.code as category_code', 'suppliers.name as supplier_name', 'brands.name_zh as brand_zh', 'brands.name_en as brand_name')
            ->orderBy('rules.id')->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    /**
     * 获取用于精确关联的普通订单字段，不加载图片或完整商品 JSON。
     *
     * @return Collection<int, Order> 当前未删除订单的轻量字段集合
     */
    public function ordinaryOrders(): Collection
    {
        return Order::query()->select('id', 'order_id', 'client_order_id', 'source_site', 'customer_name', 'order_time', 'order_status', 'classification')->orderBy('id')->get();
    }

    /**
     * 获取原始采集订单中的邮箱、国家，避免把全部原始 JSON 传入内存。
     *
     * @return Collection<int, object> 按来源订单 ID 识别的客户字段
     */
    public function sourceCustomerFields(): Collection
    {
        return DB::table('collector.source_orders')->select('order_id')
            ->selectRaw("raw #>> '{address,customerEmailAddress}' AS email, raw #>> '{address,customerCountryIsoCode}' AS country")
            ->get();
    }

    /**
     * 读取 Invoice 关联及历史首购判定所需字段。
     *
     * @return Collection<int, InvoiceOrder> 未删除的 Invoice 客户和日期字段
     */
    public function invoiceOrders(): Collection
    {
        return InvoiceOrder::query()->select('id', 'order_number', 'customer_full_name', 'customer_email', 'country', 'order_date', 'invoice_date', 'invoice_status')->orderBy('id')->get();
    }

    /**
     * 读取当前批次，供筛选项和采集历史使用。
     *
     * @return Collection<int, AnalysisImport> 当前两类来源的有效批次
     */
    public function activeImports(): Collection
    {
        return AnalysisImport::where('is_active', true)->orderBy('source_type')->get();
    }

    /**
     * 构造统一筛选查询，金额只取已确认币种及计算口径的 analysis_amount。
     *
     * @param array $filters 日期为 YYYY-MM-DD 闭区间；分类、品牌等采用数据库主键或固定枚举
     * @return Builder 当前采购批次的记录查询，尚未执行
     */
    public function rowsQuery(array $filters = []): Builder
    {
        $query = DB::table('analysis_procurement_rows as records')
            ->join('analysis_imports as imports', 'imports.id', '=', 'records.import_id')
            ->leftJoin('analysis_categories as categories', 'categories.id', '=', 'records.category_id')
            ->leftJoin('analysis_brands as brands', 'brands.id', '=', 'records.brand_id')
            ->leftJoin('analysis_suppliers as suppliers', 'suppliers.id', '=', 'records.supplier_id');
        if (isset($filters['snapshot_import_id'])) {
            $query->where('records.import_id', $filters['snapshot_import_id']);
        } else {
            $query->where('imports.is_active', true);
        }
        foreach (['startDate' => '>=', 'endDate' => '<='] as $field => $operator) {
            if (!empty($filters[$field])) {
                $query->where('records.analysis_date', $operator, $filters[$field]);
            }
        }
        foreach (['category_id', 'brand_id', 'supplier_id', 'country', 'customer_type', 'classification_status', 'sheet_name', 'record_type', 'currency'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where('records.' . $field, $filters[$field]);
            }
        }
        if (empty($filters['include_cancelled'])) {
            $query->where('records.is_cancelled', false);
        }
        if (($filters['quality'] ?? '') === 'needs_review') {
            $query->where(function (Builder $query): void {
                $query->whereNull('records.category_id')->orWhereNull('records.analysis_amount')->orWhere('records.order_match_status', '!=', 'matched');
            });
        }
        if (!empty($filters['keyword'])) {
            $keyword = '%' . addcslashes(trim($filters['keyword']), '\\%_') . '%';
            $query->where(function (Builder $query) use ($keyword): void {
                foreach (['order_reference', 'product_description', 'supplier_raw', 'customer_name', 'brand_raw'] as $index => $field) {
                    $index === 0 ? $query->where('records.' . $field, 'ilike', $keyword) : $query->orWhere('records.' . $field, 'ilike', $keyword);
                }
            });
        }

        return $query;
    }

    /**
     * 构造明细字段投影；分页接口不返回体积较大的原始行及完整推断证据。
     *
     * @param array $filters 与统计接口相同的筛选参数
     * @return Builder 可进一步分页或按主键流式导出的查询
     */
    public function detailsQuery(array $filters): Builder
    {
        return $this->rowsQuery($filters)->select(
            'records.id',
            'records.sheet_name',
            'records.row_number',
            'records.analysis_date',
            'records.date_basis',
            'records.customer_order_date',
            'records.procurement_date',
            'records.order_reference',
            'records.product_description',
            'records.supplier_raw',
            'records.customer_name',
            'records.brand_raw',
            'records.actual_price',
            'records.price_raw',
            'records.price_column',
            'records.quantity',
            'records.analysis_amount',
            'records.price_basis',
            'records.price_status',
            'records.currency',
            'records.purchase_status',
            'records.is_cancelled',
            'records.record_type',
            'records.classification_status',
            'records.order_match_status',
            'records.linked_order_type',
            'records.linked_order_id',
            'records.country',
            'records.customer_type',
            'records.issues',
            'categories.code as category_code',
            'categories.name_zh as category_zh',
            'categories.name_en as category_en',
            'brands.name_zh as brand_zh',
            'brands.name_en as brand_en',
        );
    }

    /**
     * 更新已有当前采购记录的派生分类字段，原始金额和原始行不变。
     *
     * @param array<int, array> $rows 含 id、import_id 和新分类信息的现有完整记录
     * @return void 无返回值；分批 upsert 只更新明确列出的派生字段
     */
    public function updateClassifications(array $rows): void
    {
        AnalysisProcurementRow::upsert($rows, ['id'], ['category_id', 'brand_id', 'supplier_id', 'classification_status', 'classification_evidence', 'updated_at']);
    }

    /**
     * 按主键分块读取批次，避免一次加载所有采购记录。
     *
     * @param int $importId 已存在的批次主键
     * @param callable $callback 接收每块 Eloquent Collection 的处理函数
     * @return void 无返回值；每批最多 300 行
     */
    public function chunkRows(int $importId, callable $callback): void
    {
        AnalysisProcurementRow::where('import_id', $importId)->chunkById(300, $callback);
    }
}
