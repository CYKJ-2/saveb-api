<?php

namespace App\Services;

/** 结合精确供应商、品牌和商品描述的品类候选；冲突时不强行归类。 */
class AnalysisClassificationService
{
    private array $suppliers = [];

    private array $brands = [];

    /**
     * 注入商品描述解析器。
     *
     * @param AnalysisWorkbookParser $parser 品类词和文本规范化工具
     * @return void 完成依赖初始化
     */
    public function __construct(private AnalysisWorkbookParser $parser)
    {
    }

    /**
     * 按供应商和品牌别名建立一次性查询索引。
     *
     * @param array<int, array> $rules 当前供应商优选表的数据库规则
     * @return void 替换内存索引，避免每行重复请求数据库
     */
    public function load(array $rules): void
    {
        $this->suppliers = [];
        $this->brands = [];
        foreach ($rules as $rule) {
            if ($rule['supplier_id'] && AnalysisWorkbookParser::key($rule['supplier_name']) !== '') {
                $this->suppliers[AnalysisWorkbookParser::key($rule['supplier_name'])][] = $rule;
            }
            if (!$rule['brand_id']) {
                continue;
            }
            foreach (array_unique([$rule['brand_code'], $rule['brand_name'], $rule['brand_zh']]) as $alias) {
                if ($alias !== null && AnalysisWorkbookParser::key($alias) !== '') {
                    $this->brands[AnalysisWorkbookParser::key($alias)][] = $rule;
                }
            }
        }
    }

    /**
     * 对一条采购记录计算唯一分类及证据；候选超过一个或互相冲突时保留待确认。
     *
     * @param array $row 含 supplier_raw、brand_raw、product_description 的原始采购字段
     * @param array<string, int> $categoryIds 品类 code 到数据库主键的映射
     * @return array 分类主键、状态和候选来源，不会修改原始品牌或供应商名称
     */
    public function classify(array $row, array $categoryIds): array
    {
        $supplierRules = $this->suppliers[AnalysisWorkbookParser::key($row['supplier_raw'] ?? '')] ?? [];
        $brandRules = $this->brands[AnalysisWorkbookParser::key($row['brand_raw'] ?? '')] ?? [];
        $productCategories = $this->parser->categoryWords($row['product_description'] ?? '');
        $supplierCategories = array_values(array_unique(array_column($supplierRules, 'category_code')));
        $brandCategories = array_values(array_unique(array_column($brandRules, 'category_code')));
        $evidence = [
            'supplier_categories' => $supplierCategories,
            'brand_categories' => $brandCategories,
            'product_categories' => $productCategories,
            'rule_positions' => array_values(array_unique(array_map(static fn (array $rule): string => $rule['sheet_name'] . ':' . $rule['row_number'], $supplierRules))),
        ];
        $sets = array_values(array_filter([$supplierCategories, $brandCategories, $productCategories], static fn (array $set): bool => $set !== []));
        $candidates = $sets[0] ?? [];
        foreach (array_slice($sets, 1) as $set) {
            $candidates = array_values(array_intersect($candidates, $set));
        }
        $conflict = count($sets) > 1 && $candidates === [];
        $category = count($candidates) === 1 ? $candidates[0] : null;
        $supplierIds = array_values(array_unique(array_column($supplierRules, 'supplier_id')));
        $brandIds = array_values(array_unique(array_column($brandRules, 'brand_id')));
        if (count($brandIds) > 1 && $supplierRules !== []) {
            $supplierBrandIds = array_values(array_filter(array_unique(array_column($supplierRules, 'brand_id'))));
            $brandIds = array_values(array_intersect($brandIds, $supplierBrandIds));
        }
        $evidence['category_candidates'] = $candidates;
        $evidence['brand_candidates'] = $brandIds;
        $evidence['method'] = implode('+', array_keys(array_filter([
            'supplier_exact' => $supplierRules !== [],
            'brand_exact' => $brandRules !== [],
            'product_keyword' => $productCategories !== [],
        ]))) ?: 'unmatched';

        return [
            'category_id' => $category !== null ? ($categoryIds[$category] ?? null) : null,
            'brand_id' => count($brandIds) === 1 ? $brandIds[0] : null,
            'supplier_id' => count($supplierIds) === 1 ? $supplierIds[0] : null,
            'classification_status' => $conflict ? 'conflict' : ($category ? 'matched' : ($candidates === [] ? 'unmatched' : 'ambiguous')),
            'classification_evidence' => $evidence,
        ];
    }
}
