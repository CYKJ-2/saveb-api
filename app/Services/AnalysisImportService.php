<?php

namespace App\Services;

use App\Dao\AnalysisDao;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** 上传 Excel 的月度快照采集；仅从采购表及供应商表读取数据。 */
class AnalysisImportService
{
    public const SOURCES = [
        'suppliers' => 'https://www.kdocs.cn/l/cuaeNZb47KqZ',
        'procurement' => 'https://www.kdocs.cn/l/cjdRBOAyO9dj',
    ];

    private array $categoryIds = [];

    private array $supplierIds = [];

    private int $unknownBrandId;

    private ?int $mappingImportId = null;

    /**
     * 注入解析器、分类器及数据库仓储。
     *
     * @param AnalysisDao $analysisDao 批次和明细持久化
     * @param AnalysisWorkbookReader $reader 只读取工作簿文字和公式缓存
     * @param AnalysisWorkbookParser $parser 月表字段解析
     * @param AnalysisClassificationService $classificationService 品牌品类映射
     * @return void 初始化服务依赖
     */
    public function __construct(
        private AnalysisDao $analysisDao,
        private AnalysisWorkbookReader $reader,
        private AnalysisWorkbookParser $parser,
        private AnalysisClassificationService $classificationService,
    ) {
    }

    /**
     * 首次导入历史，日常仅替换北京时间当前月；全部成功后原子切换。
     *
     * @param string $path 已验证的 XLSX 本地路径，不修改原文件
     * @param string $filename 显示用文件名
     * @param string $sourceType suppliers 或 procurement
     * @param string|null $currency 固定 CNY，其他值拒绝
     * @param string $priceBasis 固定 row_total，直接使用每行实际成交价格
     * @param int|null $userId 操作者主键；命令行可空
     * @param string $mode current_month 或 initialize，后者仅用于首次建库
     * @return array 批次 id、reused、目标月份和质量汇总；失败保留旧数据
     */
    public function import(string $path, string $filename, string $sourceType, ?string $currency = 'CNY', string $priceBasis = 'row_total', ?int $userId = null, string $mode = 'current_month'): array
    {
        if (!isset(self::SOURCES[$sourceType]) || $currency !== 'CNY' || $priceBasis !== 'row_total' || !in_array($mode, ['initialize', 'current_month'], true)) {
            throw ValidationException::withMessages(['file' => '仅支持 CNY 实际成交价格，以及首次导入／当月更新模式。']);
        }
        if (!is_file($path) || filesize($path) > 256 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'Excel 文件不存在或超过 256 MB，请移除图片后上传。']);
        }
        $month = now('Asia/Shanghai')->format('Y-m');
        $mode = $sourceType === 'suppliers' ? 'dictionary' : $mode;
        $hash = hash_file('sha256', $path);
        $signature = hash('sha256', implode('|', ['procurement-v2', $sourceType, $hash, $mode, $mode === 'current_month' ? $month : 'all', 'CNY', 'row_total']));

        return DB::transaction(function () use ($path, $filename, $sourceType, $mode, $month, $hash, $signature, $userId): array {
            DB::select('SELECT pg_advisory_xact_lock(?)', [2076091001]);
            $existing = $this->analysisDao->findImport($signature);
            if ($existing && $existing->is_active && ($sourceType === 'suppliers' || count(array_diff($existing->periods, $existing->active_periods)) === 0)) {
                return ['id' => $existing->id, 'reused' => true, 'periods' => $existing->periods, 'summary' => $existing->summary];
            }
            if ($sourceType === 'procurement' && $mode === 'initialize' && $this->analysisDao->hasProcurement()) {
                throw ValidationException::withMessages(['mode' => '已存在历史采购数据，请使用只更新当月模式。']);
            }
            $this->prepareDictionaries();
            $this->loadMappings();
            $import = $existing ?? $this->analysisDao->createImport([
                'source_type' => $sourceType, 'source_url' => self::SOURCES[$sourceType],
                'filename' => mb_substr(basename($filename), 0, 255), 'file_hash' => $hash, 'signature' => $signature,
                'currency' => 'CNY', 'price_basis' => 'row_total', 'created_by' => $userId,
                'mode' => $mode, 'summary' => [], 'periods' => [], 'active_periods' => [],
            ]);
            if ($existing && $sourceType === 'procurement') {
                $this->analysisDao->activate($import, $import->summary, [$month]);
                $this->reclassify();
                $this->analysisDao->refreshCustomerHistory();

                return ['id' => $import->id, 'reused' => true, 'periods' => [$month], 'summary' => $import->summary];
            }
            $summary = ['sheets' => [], 'rows' => 0, 'eligible_rows' => 0, 'amount' => '0.00', 'price_missing_or_invalid' => 0,
                'missing_date' => 0, 'missing_customer' => 0, 'unclassified_brand' => 0, 'unclassified_category' => 0];
            $periods = [];
            $supplierRules = [];
            $accept = $sourceType === 'procurement' && $mode === 'current_month'
                ? fn (string $name): bool => $this->parser->period($name) === $month : null;
            foreach ($this->reader->sheets($path, $accept) as $sheet) {
                if ($sourceType === 'suppliers') {
                    $records = $this->parser->supplierRules($sheet);
                    array_push($supplierRules, ...$records);
                } else {
                    $period = $this->parser->period($sheet['name']);
                    if (!$period) {
                        throw ValidationException::withMessages(['file' => '采购表包含无法识别的月份 Sheet：' . $sheet['name']]);
                    }
                    if (in_array($period, $periods, true)) {
                        throw ValidationException::withMessages(['file' => '同一月份存在多个 Sheet，无法确定替换边界：' . $period]);
                    }
                    $records = $this->parser->procurementRows($sheet, 'CNY', 'row_total');
                    if ($records === []) {
                        throw ValidationException::withMessages(['file' => '月份 Sheet 没有采购记录，原数据已保留：' . $period]);
                    }
                    $periods[] = $period;
                    $this->ensureSuppliers($records);
                    $this->loadMappings();
                    $timestamp = now()->toDateTimeString();
                    foreach ($records as &$record) {
                        $record = array_merge($record, $this->classificationService->classify($record, $this->categoryIds));
                        $record += ['import_id' => $import->id, 'mapping_import_id' => $this->mappingImportId,
                            'mapped_at' => $timestamp, 'created_at' => $timestamp, 'updated_at' => $timestamp];
                        $summary['eligible_rows'] += $record['is_eligible'] ? 1 : 0;
                        $summary['amount'] = bcadd($summary['amount'], $record['analysis_amount'] ?? '0', 2);
                        $summary['price_missing_or_invalid'] += $record['price_status'] !== 'valid' ? 1 : 0;
                        $summary['missing_date'] += $record['analysis_date'] === null ? 1 : 0;
                        $summary['missing_customer'] += $record['customer_key'] === null ? 1 : 0;
                        $summary['unclassified_brand'] += $record['brand_match_status'] !== 'matched' ? 1 : 0;
                        $summary['unclassified_category'] += $record['classification_status'] !== 'matched' ? 1 : 0;
                        $record = $this->serialize($record);
                    }
                    unset($record);
                    $this->analysisDao->insertRows($records);
                }
                $summary['sheets'][] = ['name' => $sheet['name'], 'rows' => count($records)];
                $summary['rows'] += count($records);
            }
            if ($summary['rows'] === 0) {
                throw ValidationException::withMessages(['file' => $mode === 'current_month' ? "文件缺少 {$month} 的有效采购 Sheet，原数据已保留。" : '没有可导入的数据，原数据已保留。']);
            }
            if ($sourceType === 'suppliers') {
                $this->saveSupplierMappings($supplierRules, $import->id);
            }
            $destination = 'analysis/imports/' . $hash . '.xlsx';
            if (!Storage::disk('local')->exists($destination) && !Storage::disk('local')->putFileAs('analysis/imports', new File($path), $hash . '.xlsx')) {
                throw ValidationException::withMessages(['file' => '无法保存私有来源副本，原数据已保留。']);
            }
            sort($periods);
            $this->analysisDao->activate($import, $summary, $periods);
            if ($sourceType === 'suppliers') {
                $this->mappingImportId = $import->id;
                $this->loadMappings();
                $this->reclassify();
            } else {
                $this->analysisDao->refreshCustomerHistory();
            }

            return ['id' => $import->id, 'reused' => $existing !== null, 'periods' => $periods, 'summary' => $summary];
        });
    }

    /**
     * 初始化固定品类和唯一待分类品牌。
     *
     * @return void 写入并缓存字典主键
     */
    private function prepareDictionaries(): void
    {
        foreach (AnalysisWorkbookParser::CATEGORIES as $code => [$zh, $en]) {
            $this->categoryIds[$code] = $this->analysisDao->dictionary('category', $code, ['name_zh' => $zh, 'name_en' => $en]);
        }
        $this->unknownBrandId = $this->analysisDao->dictionary('brand', hash('sha256', '__unclassified__'), [
            'name_zh' => '待分类', 'name_en' => 'Unclassified', 'is_active' => true,
        ]);
    }

    /**
     * 保存品牌定义和供应商映射，四张业务表即可完成分类。
     *
     * @param array $rules 供应商工作簿所有 Sheet 的规则
     * @param int $importId 字典来源批次
     * @return void 品牌规则写入品牌字典，供应关系写入供应商字典 JSON
     */
    private function saveSupplierMappings(array $rules, int $importId): void
    {
        $this->analysisDao->clearMappings();
        $this->prepareDictionaries();
        $brands = [];
        $suppliers = [];
        foreach ($rules as &$rule) {
            $rule['brand_id'] = null;
            if ($rule['brand_name']) {
                $identity = hash('sha256', AnalysisWorkbookParser::key($rule['brand_name']));
                if (!isset($brands[$identity])) {
                    $brands[$identity] = ['id' => $this->analysisDao->dictionary('brand', $identity, [
                        'name_zh' => $rule['brand_zh'] ?: $rule['brand_name'], 'name_en' => $rule['brand_name'], 'is_active' => true,
                    ]), 'aliases' => [], 'entries' => []];
                }
                $rule['brand_id'] = $brands[$identity]['id'];
                $brands[$identity]['aliases'][] = $rule['brand_code'];
            }
            if ($rule['supplier_name']) {
                $key = AnalysisWorkbookParser::key($rule['supplier_name']);
                $suppliers[$key]['name'] = $rule['supplier_name'];
                $suppliers[$key]['rules'][] = $rule;
            } elseif ($rule['brand_id']) {
                $brands[$identity]['entries'][] = $rule;
            } elseif ($rule['brand_pending']) {
                $brands['__pending__']['entries'][] = $rule;
            }
        }
        unset($rule);
        foreach ($brands as $identity => $brand) {
            if ($identity === '__pending__') {
                $this->analysisDao->dictionary('brand', hash('sha256', '__unclassified__'), ['source_entries' => $brand['entries'], 'is_active' => true]);
            } else {
                $this->analysisDao->dictionary('brand', $identity, [
                    'aliases' => array_values(array_unique(array_filter($brand['aliases']))), 'source_entries' => $brand['entries'],
                ]);
            }
        }
        foreach ($suppliers as $key => $supplier) {
            $this->analysisDao->dictionary('supplier', hash('sha256', $key), [
                'name' => $supplier['name'], 'mapping_rules' => $supplier['rules'], 'rules_import_id' => $importId,
            ]);
        }
    }

    /**
     * 为采购表中出现的新供应商创建字典，未知供应关系不作推测。
     *
     * @param array $records 当前 Sheet 的采购记录
     * @return void 更新供应商主键缓存
     */
    private function ensureSuppliers(array $records): void
    {
        foreach ($records as $record) {
            $name = $record['supplier_raw'];
            $key = AnalysisWorkbookParser::key($name);
            if ($key !== '' && !isset($this->supplierIds[$key])) {
                $this->supplierIds[$key] = $this->analysisDao->dictionary('supplier', hash('sha256', $key), ['name' => $name]);
            }
        }
    }

    /**
     * 装载当前字典并建立内存匹配索引。
     *
     * @return void 更新当前规则、供应商缓存和字典批次
     */
    private function loadMappings(): void
    {
        $mapping = $this->analysisDao->mappings();
        $this->supplierIds = $mapping['suppliers'];
        $this->mappingImportId = $this->analysisDao->supplierImportId();
        $this->classificationService->load($mapping['rules'], $mapping['brands'], $mapping['suppliers'], $this->unknownBrandId);
    }

    /**
     * 供应商表更新后重算有效采购历史的品牌和品类，保留原始值及金额。
     *
     * @return void 分块更新派生字段，调用方持有事务锁
     */
    private function reclassify(): void
    {
        $this->analysisDao->chunkCurrentRows(function ($rows): void {
            $updates = [];
            foreach ($rows as $row) {
                $record = $row->getAttributes();
                $updates[] = $this->serialize(array_merge($record, $this->classificationService->classify($record, $this->categoryIds), [
                    'mapping_import_id' => $this->mappingImportId, 'mapped_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
                ]));
            }
            $this->analysisDao->updateClassifications($updates);
        });
    }

    /**
     * 将批量写入中的结构字段编码为 JSON。
     *
     * @param array $record 原始和派生记录
     * @return array 可供 insert/upsert 的数据库字段
     */
    private function serialize(array $record): array
    {
        foreach (['raw', 'issues', 'classification_evidence'] as $field) {
            if (isset($record[$field]) && is_array($record[$field])) {
                $record[$field] = json_encode($record[$field], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
        }

        return $record;
    }
}
