<?php

namespace App\Services;

use App\Dao\AnalysisDao;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** 从只读 Excel 副本导入可追溯快照；不向金山文档回写任何内容。 */
class AnalysisImportService
{
    public const SOURCES = [
        'suppliers' => 'https://www.kdocs.cn/l/cuaeNZb47KqZ',
        'procurement' => 'https://www.kdocs.cn/l/cjdRBOAyO9dj',
    ];

    /**
     * 注入解析、分类、订单关联和存储依赖。
     *
     * @param AnalysisDao $analysisDao 批次与记录持久化
     * @param AnalysisWorkbookReader $reader 安全 XLSX 读取器
     * @param AnalysisWorkbookParser $parser 跨月份字段解析器
     * @param AnalysisClassificationService $classificationService 供应商及品类规则匹配器
     * @param AnalysisOrderLinkService $orderLinkService 普通订单及 Invoice 关联器
     * @return void 初始化导入流程依赖
     */
    public function __construct(
        private AnalysisDao $analysisDao,
        private AnalysisWorkbookReader $reader,
        private AnalysisWorkbookParser $parser,
        private AnalysisClassificationService $classificationService,
        private AnalysisOrderLinkService $orderLinkService,
    ) {
    }

    /**
     * 导入完整工作簿快照，成功后一次切换当前版本；重复文件及口径不重复累计。
     *
     * @param string $path XLSX 本地路径，仅供内部命令或已验证的上传文件调用
     * @param string $filename 来源显示文件名，不参与路径拼接
     * @param string $sourceType suppliers 或 procurement
     * @param string|null $currency 采购价格币种；null 为待确认，金额汇总不纳入这些行
     * @param string $priceBasis row_total、unit 或 unknown；单件价缺数量时不计算行金额
     * @param int|null $userId 操作者主键；命令行执行时可为空
     * @return array 批次 ID、是否复用、各 sheet 数量及质量汇总
     */
    public function import(string $path, string $filename, string $sourceType, ?string $currency, string $priceBasis, ?int $userId): array
    {
        if (!isset(self::SOURCES[$sourceType]) || !in_array($priceBasis, ['row_total', 'unit', 'unknown'], true) || ($currency !== null && !preg_match('/^[A-Z]{3}$/', $currency))) {
            throw ValidationException::withMessages(['source_type' => 'Invalid import settings.']);
        }
        if (!is_file($path) || filesize($path) > 64 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'XLSX file is missing or exceeds 64 MB.']);
        }
        $hash = hash_file('sha256', $path);
        $signature = hash('sha256', implode('|', [$sourceType, $hash, $currency ?? '', $priceBasis]));

        return DB::transaction(function () use ($path, $filename, $sourceType, $currency, $priceBasis, $userId, $hash, $signature): array {
            // 两类来源共享锁，避免导入采购时恰好切换供应商规则版本。
            DB::select('SELECT pg_advisory_xact_lock(?)', [2076091001]);
            $existing = $this->analysisDao->findImport($signature);
            if ($existing && $existing->is_active) {
                return ['id' => $existing->id, 'reused' => true, 'summary' => $existing->summary];
            }
            if ($existing) {
                $this->analysisDao->activate($existing, $existing->summary);
                $this->reclassify();

                return ['id' => $existing->id, 'reused' => true, 'summary' => $existing->summary];
            }
            $categoryIds = $this->categoryIds();
            $this->classificationService->load($this->analysisDao->activeRules());
            if ($sourceType === 'procurement') {
                $this->orderLinkService->load();
            }
            $import = $this->analysisDao->createImport([
                'source_type' => $sourceType,
                'source_url' => self::SOURCES[$sourceType],
                'filename' => mb_substr(basename($filename), 0, 255),
                'file_hash' => $hash,
                'signature' => $signature,
                'currency' => $currency,
                'price_basis' => $priceBasis,
                'created_by' => $userId,
                'summary' => [],
            ]);
            $summary = ['sheets' => [], 'rows' => 0, 'price_missing_or_invalid' => 0, 'amount_unavailable' => 0, 'category_matched' => 0, 'brand_matched' => 0, 'orders_matched' => 0, 'cancelled' => 0];
            $dictionaryIds = ['brands' => [], 'suppliers' => []];
            foreach ($this->reader->sheets($path) as $sheet) {
                $timestamp = now()->toDateTimeString();
                if ($sourceType === 'suppliers') {
                    $rules = $this->parser->supplierRules($sheet);
                    $records = $this->ruleRecords($rules, $categoryIds, $dictionaryIds, $import->id, $timestamp);
                    $this->analysisDao->insertRules($records);
                } else {
                    $records = $this->parser->procurementRows($sheet, $currency, $priceBasis);
                    foreach ($records as &$record) {
                        $record = array_merge($record, $this->classificationService->classify($record, $categoryIds), $this->orderLinkService->link($record));
                        $summary['price_missing_or_invalid'] += $record['price_status'] !== 'valid' ? 1 : 0;
                        $summary['amount_unavailable'] += $record['analysis_amount'] === null ? 1 : 0;
                        $summary['category_matched'] += $record['category_id'] !== null ? 1 : 0;
                        $summary['brand_matched'] += $record['brand_id'] !== null ? 1 : 0;
                        $summary['orders_matched'] += $record['order_match_status'] === 'matched' ? 1 : 0;
                        $summary['cancelled'] += $record['is_cancelled'] ? 1 : 0;
                        $record += ['import_id' => $import->id, 'created_at' => $timestamp, 'updated_at' => $timestamp];
                        $record = $this->serialize($record);
                    }
                    unset($record);
                    $this->analysisDao->insertRows($records);
                }
                $summary['sheets'][] = ['name' => $sheet['name'], 'rows' => count($records)];
                $summary['rows'] += count($records);
            }
            if ($summary['rows'] === 0) {
                throw ValidationException::withMessages(['file' => 'No supported data rows found; current data was kept.']);
            }
            if (!Storage::disk('local')->putFileAs('analysis/imports', new File($path), $hash . '.xlsx')) {
                throw ValidationException::withMessages(['file' => 'Unable to save the private source copy; current data was kept.']);
            }
            $this->analysisDao->activate($import, $summary);
            if ($sourceType === 'suppliers') {
                $this->reclassify();
            }

            return ['id' => $import->id, 'reused' => false, 'summary' => $summary];
        });
    }

    /**
     * 建立品类字典并返回主键索引。
     *
     * @return array<string, int> 品类 code 到 ID 的映射
     */
    private function categoryIds(): array
    {
        $ids = [];
        foreach (AnalysisWorkbookParser::CATEGORIES as $code => $names) {
            $ids[$code] = $this->analysisDao->category($code, $names);
        }

        return $ids;
    }

    /**
     * 将规则中的品牌、供应商名称转换成字典主键，缓存避免逐条重复查询。
     *
     * @param array<int, array> $rules 来源规则
     * @param array<string, int> $categoryIds 品类主键索引
     * @param array $dictionaryIds 原地更新的品牌、供应商字典缓存
     * @param int $importId 当前导入批次主键
     * @param string $timestamp 当前批次写入时间
     * @return array<int, array> 可批量插入的规则记录
     */
    private function ruleRecords(array $rules, array $categoryIds, array &$dictionaryIds, int $importId, string $timestamp): array
    {
        $records = [];
        foreach ($rules as $rule) {
            $brandId = null;
            if ($rule['brand_name']) {
                $brandKey = hash('sha256', AnalysisWorkbookParser::key($rule['brand_name']));
                $brandId = $dictionaryIds['brands'][$brandKey] ??= $this->analysisDao->brand($brandKey, $rule['brand_zh'], $rule['brand_name']);
            }
            $supplierId = null;
            if ($rule['supplier_name']) {
                $supplierKey = hash('sha256', AnalysisWorkbookParser::key($rule['supplier_name']));
                $supplierId = $dictionaryIds['suppliers'][$supplierKey] ??= $this->analysisDao->supplier($supplierKey, $rule['supplier_name']);
            }
            $records[] = [
                'import_id' => $importId,
                'category_id' => $categoryIds[$rule['category_code']],
                'brand_id' => $brandId,
                'supplier_id' => $supplierId,
                'brand_code' => $rule['brand_code'],
                'sheet_name' => $rule['sheet_name'],
                'row_number' => $rule['row_number'],
                'preference' => $rule['preference'],
                'raw' => json_encode($rule['raw'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        return $records;
    }

    /**
     * 供应商版本变化后分批重算当前采购行的分类，不改变原始记录和价格。
     *
     * @return void 无返回值；调用方持有导入事务锁
     */
    private function reclassify(): void
    {
        $categoryIds = $this->categoryIds();
        $this->classificationService->load($this->analysisDao->activeRules());
        $activeId = $this->analysisDao->activeImports()->firstWhere('source_type', 'procurement')?->id;
        if (!$activeId) {
            return;
        }
        $this->analysisDao->chunkRows($activeId, function ($rows) use ($categoryIds): void {
            $updates = [];
            foreach ($rows as $row) {
                $attributes = $row->getAttributes();
                $classification = $this->classificationService->classify($attributes, $categoryIds);
                $updates[] = $this->serialize(array_merge($attributes, $classification, ['updated_at' => now()->toDateTimeString()]));
            }
            $this->analysisDao->updateClassifications($updates);
        });
    }

    /**
     * 将结构字段编码为数据库 JSON，避免批量写入时丢失 Eloquent 类型转换。
     *
     * @param array $record 待写入行；已有 JSON 字符串保持不变
     * @return array JSON 字段已编码的记录
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
