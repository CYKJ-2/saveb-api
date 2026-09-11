<?php

namespace App\Dao;

use App\Models\AnalysisBrand;
use App\Models\AnalysisCategory;
use App\Models\AnalysisImport;
use App\Models\AnalysisProcurementRow;
use App\Models\AnalysisSupplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/** 采购分析的持久化和 SQL 聚合；统计只读取采购明细快照。 */
class AnalysisDao
{
    public const PRICE_BAND = "CASE WHEN r.actual_price < 0 THEN 'negative' WHEN r.actual_price < 100 THEN '0-99.99' WHEN r.actual_price < 300 THEN '100-299.99' WHEN r.actual_price < 500 THEN '300-499.99' WHEN r.actual_price < 1000 THEN '500-999.99' WHEN r.actual_price < 3000 THEN '1000-2999.99' ELSE '3000+' END";

    public const DIMENSIONS = [
        'brand' => ['r.brand_id::text', 'r.brand_name', 'r.brand_name_en'],
        'category' => ['r.category_code', 'r.category_name', 'r.category_name_en'],
        'supplier' => ["COALESCE(r.supplier_name, 'unknown')", "COALESCE(r.supplier_name, '未知')", "COALESCE(r.supplier_name, 'Unknown')"],
        'customer_type' => ['r.customer_type', 'r.customer_type', 'r.customer_type'],
        'purchase_method' => ['r.purchase_method', 'r.purchase_method', 'r.purchase_method'],
        'price_band' => [self::PRICE_BAND, self::PRICE_BAND, self::PRICE_BAND],
    ];

    /**
     * 建立统一筛选，不把缺失金额当成零；质量查询可读取全部记录。
     *
     * @param array $filters 日期、字典主键、客户、采购方式、质量及快照边界
     * @param bool $eligible 是否只读取可统计金额的行
     * @return Builder 尚未执行的采购明细查询
     */
    private function query(array $filters, bool $eligible = true): Builder
    {
        $query = DB::table('analysis_procurement_rows as r');
        if (isset($filters['snapshot'])) {
            $query->where(function (Builder $partitions) use ($filters): void {
                $partitions->whereRaw('FALSE');
                foreach ($filters['snapshot'] as $period => $importId) {
                    $partitions->orWhere(function (Builder $partition) use ($period, $importId): void {
                        $partition->where('r.source_period', $period)->where('r.import_id', $importId);
                    });
                }
            });
        } else {
            $query->where('r.is_current', true);
        }
        if ($eligible) {
            $query->where('r.is_eligible', true);
        }
        foreach (['startDate' => ['r.analysis_date', '>='], 'endDate' => ['r.analysis_date', '<=']] as $key => [$column, $operator]) {
            if (!empty($filters[$key])) {
                $query->where($column, $operator, $filters[$key]);
            }
        }
        foreach (['brand_id', 'category_id', 'supplier_id', 'customer_type', 'purchase_method', 'customer_key', 'source_period'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query->where('r.' . $key, $filters[$key]);
            }
        }
        if (!empty($filters['keyword'])) {
            $search = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $filters['keyword']) . '%';
            $query->where(function (Builder $searchQuery) use ($search): void {
                foreach (['customer_name', 'order_reference', 'brand_raw', 'product_description', 'supplier_raw'] as $column) {
                    $searchQuery->orWhere('r.' . $column, 'ilike', $search);
                }
            });
        }
        if (!empty($filters['price_band']) && in_array($filters['price_band'], ['negative', '0-99.99', '100-299.99', '300-499.99', '500-999.99', '1000-2999.99', '3000+'], true)) {
            $query->where('r.price_status', 'valid')
                ->whereRaw('(' . self::PRICE_BAND . ') = ?', [$filters['price_band']]);
        }
        if (($filters['quality'] ?? '') === 'missing') {
            $query->whereRaw("(r.price_status != 'valid' OR r.analysis_date IS NULL OR r.customer_key IS NULL OR r.brand_raw IS NULL OR r.supplier_raw IS NULL)");
        } elseif (($filters['quality'] ?? '') === 'classification') {
            $query->whereRaw("(r.classification_status != 'matched' OR r.brand_match_status != 'matched')");
        } elseif (($filters['quality'] ?? '') === 'needs_review') {
            $query->whereRaw("(r.issues != '[]'::jsonb OR r.classification_status != 'matched' OR r.brand_match_status != 'matched')");
        }

        return $query;
    }

    /**
     * 返回金额总览及同一筛选下的数据质量计数。
     *
     * @param array $filters 统一筛选条件
     * @return array CNY 两位金额、有效记录数、客户数、缺失数和日期边界
     */
    public function summary(array $filters): array
    {
        return (array) $this->query($filters, false)->selectRaw("COUNT(*) AS rows,
            COUNT(*) FILTER (WHERE r.is_eligible) AS eligible_rows,
            COALESCE(ROUND(SUM(r.analysis_amount), 2), 0)::numeric(22,2)::text AS amount,
            COALESCE(ROUND(AVG(r.analysis_amount), 2), 0)::numeric(22,2)::text AS average_amount,
            COUNT(DISTINCT r.customer_key) FILTER (WHERE r.is_eligible) AS customers,
            COUNT(*) FILTER (WHERE r.price_status != 'valid') AS missing_price,
            COUNT(*) FILTER (WHERE r.analysis_date IS NULL) AS missing_date,
            COUNT(*) FILTER (WHERE r.customer_key IS NULL) AS missing_customer,
            COUNT(*) FILTER (WHERE r.brand_match_status != 'matched') AS unclassified_brand,
            COUNT(*) FILTER (WHERE r.classification_status != 'matched') AS unclassified_category,
            COALESCE(SUM(r.analysis_amount) FILTER (WHERE r.analysis_date IS NULL), 0)::numeric(22,2)::text AS undated_amount,
            MIN(r.analysis_date) FILTER (WHERE r.is_eligible) AS first_date,
            MAX(r.analysis_date) FILTER (WHERE r.is_eligible) AS last_date")->first();
    }

    /**
     * 汇总已采购且有实际成交价格的日／月趋势。
     *
     * @param array $filters 统一筛选条件
     * @param string $grain day 或 month，由控制器白名单校验
     * @return array 各日期金额和采购记录数；不含缺日期记录
     */
    public function trend(array $filters, string $grain): array
    {
        $period = $grain === 'day' ? 'r.analysis_date::text' : "to_char(r.analysis_date, 'YYYY-MM')";

        return $this->query($filters)->whereNotNull('r.analysis_date')
            ->selectRaw("$period AS period, COUNT(*) AS rows, SUM(r.analysis_amount)::numeric(22,2)::text AS amount")
            ->groupByRaw($period)->orderBy('period')->get()->all();
    }

    /**
     * 按指定单维度聚合，金额占比使用未截断的全部分组作分母。
     *
     * @param array $filters 统一筛选条件
     * @param string $dimension DIMENSIONS 中的固定维度名
     * @return array 排名、名称、记录数、两位金额和两位百分比
     */
    public function distribution(array $filters, string $dimension): array
    {
        [$key, $zh, $en] = self::DIMENSIONS[$dimension];

        return $this->query($filters)
            ->selectRaw("$key AS key, $zh AS name_zh, $en AS name_en, COUNT(*) AS rows,
                SUM(r.analysis_amount)::numeric(22,2)::text AS amount,
                COALESCE(ROUND(SUM(r.analysis_amount) * 100 / NULLIF(SUM(SUM(r.analysis_amount)) OVER (), 0), 2), 0)::text AS share")
            ->groupByRaw(implode(', ', array_unique([$key, $zh, $en])))
            ->orderByRaw('SUM(r.analysis_amount) DESC')->orderBy('key')->get()->all();
    }

    /**
     * 聚合品牌／品类／价位／首复购交叉矩阵，保留全部组合。
     *
     * @param array $filters 统一筛选条件
     * @param string $left 固定第一维度名
     * @param string $right 固定第二维度名
     * @return array 两个维度的主键和中英文名称、记录数、两位 CNY 金额及占筛选总额的百分比；总额为零时占比为 0.00
     */
    public function cross(array $filters, string $left, string $right): array
    {
        [$x, $xz, $xe] = self::DIMENSIONS[$left];
        [$y, $yz, $ye] = self::DIMENSIONS[$right];

        return $this->query($filters)
            ->selectRaw("$x AS x, $xz AS x_zh, $xe AS x_en, $y AS y, $yz AS y_zh, $ye AS y_en,
                COUNT(*) AS rows, SUM(r.analysis_amount)::numeric(22,2)::text AS amount,
                COALESCE(ROUND(SUM(r.analysis_amount) * 100 / NULLIF(SUM(SUM(r.analysis_amount)) OVER (), 0), 2), 0)::numeric(22,2)::text AS share")
            ->groupByRaw(implode(', ', array_unique([$x, $xz, $xe, $y, $yz, $ye])))
            ->orderByRaw('SUM(r.analysis_amount) DESC')->orderBy('x')->orderBy('y')->get()->all();
    }

    /**
     * 返回字典和当前月份版本供页面初始化。
     *
     * @return array 筛选项、有效批次和是否允许首次全量导入
     */
    public function options(): array
    {
        return [
            'brands' => AnalysisBrand::where('is_active', true)->orderBy('name_en')->get(['id', 'name_zh', 'name_en']),
            'categories' => AnalysisCategory::orderBy('id')->get(['id', 'code', 'name_zh', 'name_en']),
            'suppliers' => AnalysisSupplier::orderBy('name')->get(['id', 'name']),
            'periods' => AnalysisProcurementRow::where('is_current', true)->distinct()->orderBy('source_period')->pluck('source_period'),
            'imports' => AnalysisImport::where('is_active', true)->orderByDesc('id')->get(),
            'can_initialize' => !AnalysisProcurementRow::where('is_current', true)->exists(),
            'current_month' => now('Asia/Shanghai')->format('Y-m'),
            'currency' => 'CNY',
        ];
    }

    /**
     * 在数据库中分页读取明细或质量列表。
     *
     * @param array $filters scope eligible/all、quality 及统一筛选分页参数
     * @return LengthAwarePaginator 当前页原始及派生字段，默认二十条
     */
    public function listing(array $filters): LengthAwarePaginator
    {
        return $this->query($filters, ($filters['scope'] ?? 'eligible') !== 'all' && empty($filters['quality']))
            ->select('r.*')->orderByRaw('r.analysis_date DESC NULLS LAST')->orderByDesc('r.id')
            ->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
    }

    /**
     * 按标准客户名汇总并在 SQL 中分页，首次日期来自完整历史。
     *
     * @param array $filters 统一日期及业务筛选和分页参数
     * @return LengthAwarePaginator 客户金额、记录数、品牌品类数量及首复购金额
     */
    public function customers(array $filters): LengthAwarePaginator
    {
        return $this->query($filters)->whereNotNull('r.customer_key')->groupBy('r.customer_key')
            ->selectRaw("r.customer_key, MIN(r.customer_name) AS customer_name, COUNT(*) AS rows,
                MIN(r.customer_first_date) AS first_date, MAX(r.analysis_date) AS last_date,
                COUNT(DISTINCT r.brand_id) AS brands, COUNT(DISTINCT r.category_id) AS categories,
                SUM(r.analysis_amount)::numeric(22,2)::text AS amount,
                COALESCE(SUM(r.analysis_amount) FILTER (WHERE r.customer_type = 'first'),0)::numeric(22,2)::text AS first_amount,
                COALESCE(SUM(r.analysis_amount) FILTER (WHERE r.customer_type = 'returning'),0)::numeric(22,2)::text AS returning_amount")
            ->orderByRaw('SUM(r.analysis_amount) DESC')->orderBy('r.customer_key')
            ->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
    }

    /**
     * 固定月份和批次的组合后分块导出，避免更新当月时串入旧月份数据。
     *
     * @param array $filters 与明细列表相同的筛选条件
     * @return LazyCollection 每块五百条的版本固定结果
     */
    public function exportRows(array $filters): LazyCollection
    {
        $filters['snapshot'] = AnalysisProcurementRow::where('is_current', true)->select('source_period', 'import_id')->distinct()->pluck('import_id', 'source_period')->all();

        return $this->query($filters, ($filters['scope'] ?? 'eligible') !== 'all' && empty($filters['quality']))
            ->select('r.*')->orderByRaw('r.analysis_date DESC NULLS LAST')->orderByDesc('r.id')->lazy(500);
    }

    /**
     * 查询当前有效明细及私有来源信息。
     *
     * @param int $id 采购记录主键
     * @return object|null 含原始字段和匹配证据；旧版本不对页面展示
     */
    public function evidence(int $id): ?object
    {
        return $this->query([], false)->join('analysis_imports as i', 'i.id', '=', 'r.import_id')
            ->where('r.id', $id)->select('r.*', 'i.filename')->first();
    }

    /**
     * 在数据库中分页查询上传历史。
     *
     * @param array $filters page 和 per_page
     * @return LengthAwarePaginator 文件名、版本月份、操作者名称及导入汇总
     */
    public function imports(array $filters): LengthAwarePaginator
    {
        return AnalysisImport::query()->leftJoin('users as u', 'u.id', '=', 'analysis_imports.created_by')
            ->select('analysis_imports.*', 'u.display_name as operator_name')->orderByDesc('analysis_imports.id')
            ->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
    }

    /**
     * 保存三类字典模型，按稳定业务键复用主键。
     *
     * @param string $type category、brand 或 supplier
     * @param string $identity 品类 code 或规范名称哈希
     * @param array $attributes 字典名称及映射信息
     * @return int 新建或已存在字典主键
     */
    public function dictionary(string $type, string $identity, array $attributes): int
    {
        $model = match ($type) {
            'category' => AnalysisCategory::class,
            'brand' => AnalysisBrand::class,
            'supplier' => AnalysisSupplier::class,
        };

        return $model::updateOrCreate([$type === 'category' ? 'code' : 'identity' => $identity], $attributes)->id;
    }

    /**
     * 装载品牌、供应商映射及全部来源规则。
     *
     * @return array 品牌主键索引、规范供应商索引及规则列表
     */
    public function mappings(): array
    {
        $brands = AnalysisBrand::where('is_active', true)->get()->keyBy('id')->toArray();
        $suppliers = [];
        $rules = [];
        foreach (AnalysisSupplier::all() as $supplier) {
            $suppliers[\App\Services\AnalysisWorkbookParser::key($supplier->name)] = $supplier->id;
            array_push($rules, ...($supplier->mapping_rules ?? []));
        }
        foreach ($brands as $brand) {
            array_push($rules, ...($brand['source_entries'] ?? []));
        }

        return compact('brands', 'suppliers', 'rules');
    }

    /**
     * 清空旧字典的当前映射，保留主键和历史快照以便追溯。
     *
     * @return void 在供应商导入事务内执行
     */
    public function clearMappings(): void
    {
        AnalysisSupplier::query()->update(['mapping_rules' => '[]', 'rules_import_id' => null]);
        AnalysisBrand::query()->update(['aliases' => '[]', 'source_entries' => '[]', 'is_active' => false]);
    }

    /**
     * 查找幂等导入批次。
     *
     * @param string $signature 文件、模式和目标月份的哈希
     * @return AnalysisImport|null 已完成批次或 null
     */
    public function findImport(string $signature): ?AnalysisImport
    {
        return AnalysisImport::where('signature', $signature)->first();
    }

    /**
     * 创建技术导入批次，不影响当前业务数据。
     *
     * @param array $attributes 批次来源、文件哈希、模式和操作者
     * @return AnalysisImport 新批次模型
     */
    public function createImport(array $attributes): AnalysisImport
    {
        return AnalysisImport::create($attributes);
    }

    /**
     * 批量写入尚未激活的采购明细。
     *
     * @param array $records 已编码 JSON 的明细记录
     * @return void 每批最多三百条，避免 PostgreSQL 参数数量过大
     */
    public function insertRows(array $records): void
    {
        foreach (array_chunk($records, 300) as $chunk) {
            AnalysisProcurementRow::insert($chunk);
        }
    }

    /**
     * 判断是否已有有效采购历史。
     *
     * @return bool 有历史为 true，禁止再次全量覆盖
     */
    public function hasProcurement(): bool
    {
        return AnalysisProcurementRow::where('is_current', true)->exists();
    }

    /**
     * 读取当前供应商来源批次。
     *
     * @return int|null 当前字典批次主键；尚未导入时为空
     */
    public function supplierImportId(): ?int
    {
        return AnalysisImport::where('source_type', 'suppliers')->where('is_active', true)->value('id');
    }

    /**
     * 原子激活指定月份，其余月份的记录和批次保持有效。
     *
     * @param AnalysisImport $import 已解析成功的导入批次
     * @param array $summary 各月记录数和数据质量摘要
     * @param array $periods 本次允许替换的 YYYY-MM 列表
     * @return void 在导入锁和事务内切换有效版本
     */
    public function activate(AnalysisImport $import, array $summary, array $periods): void
    {
        if ($import->source_type === 'procurement') {
            AnalysisProcurementRow::where('is_current', true)->whereIn('source_period', $periods)->update(['is_current' => false]);
            AnalysisProcurementRow::where('import_id', $import->id)->whereIn('source_period', $periods)->update(['is_current' => true]);
            foreach (AnalysisImport::where('source_type', 'procurement')->where('is_active', true)->get() as $previous) {
                $remaining = array_values(array_diff($previous->active_periods ?? [], $periods));
                $previous->update(['active_periods' => $remaining, 'is_active' => $remaining !== []]);
            }
        } else {
            AnalysisImport::where('source_type', 'suppliers')->where('is_active', true)->update(['is_active' => false, 'active_periods' => '[]']);
        }
        $import->update(['is_active' => true, 'periods' => $periods, 'active_periods' => $periods, 'summary' => $summary, 'status' => 'completed']);
    }

    /**
     * 从所有有效采购月份计算客户首次采购日，筛选不会改变首购身份。
     *
     * @return void 更新有效明细的首次日期和 first/returning/unknown
     */
    public function refreshCustomerHistory(): void
    {
        DB::statement("WITH history AS (
            SELECT customer_key, MIN(analysis_date) AS first_date
            FROM analysis_procurement_rows WHERE is_current AND is_eligible AND customer_key IS NOT NULL AND analysis_date IS NOT NULL
            GROUP BY customer_key
        ), classified AS (
            SELECT r.id, h.first_date,
            CASE WHEN NOT r.is_eligible OR r.analysis_date IS NULL OR h.first_date IS NULL THEN 'unknown'
                 WHEN r.analysis_date = h.first_date THEN 'first' ELSE 'returning' END AS customer_type
            FROM analysis_procurement_rows r LEFT JOIN history h ON h.customer_key = r.customer_key WHERE r.is_current
        )
        UPDATE analysis_procurement_rows r SET customer_first_date = c.first_date, customer_type = c.customer_type
        FROM classified c WHERE c.id = r.id");
    }

    /**
     * 分块读取有效采购记录，供供应商更新后重新分类。
     *
     * @param callable $callback 接收最多三百条模型的函数
     * @return void 不加载历史失效版本
     */
    public function chunkCurrentRows(callable $callback): void
    {
        AnalysisProcurementRow::where('is_current', true)->chunkById(300, $callback);
    }

    /**
     * 只更新派生分类快照，不改变来源或金额。
     *
     * @param array $records 含完整模型属性及新分类信息的记录
     * @return void 分块写入指定派生字段
     */
    public function updateClassifications(array $records): void
    {
        AnalysisProcurementRow::upsert($records, ['id'], ['brand_id', 'brand_name', 'brand_name_en', 'brand_match_status',
            'category_id', 'category_code', 'category_name', 'category_name_en', 'supplier_id', 'supplier_name',
            'classification_status', 'classification_evidence', 'mapping_import_id', 'mapped_at', 'updated_at']);
    }
}
