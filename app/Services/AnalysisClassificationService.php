<?php

namespace App\Services;

/** 通过供应商＋原始代号匹配品类和品牌；保留冲突证据，禁止关联订单系统。 */
class AnalysisClassificationService
{
    private array $supplierRules = [];

    private array $codeRules = [];

    private array $brands = [];

    private array $suppliers = [];

    private int $unclassifiedBrandId;

    /**
     * 注入商品描述消歧工具。
     *
     * @param AnalysisWorkbookParser $parser 原始文本解析器
     * @return void 完成依赖初始化
     */
    public function __construct(private AnalysisWorkbookParser $parser)
    {
    }

    /**
     * 为一个导入批次建立映射索引，避免逐行查询数据库。
     *
     * @param array $rules 供应商字典及品牌字典中的来源规则
     * @param array $brands 以主键为索引的品牌字典
     * @param array $suppliers 以规范名称为索引的供应商主键
     * @param int $unclassifiedBrandId 共用待分类品牌主键
     * @return void 替换当前内存映射
     */
    public function load(array $rules, array $brands, array $suppliers, int $unclassifiedBrandId): void
    {
        $this->supplierRules = [];
        $this->codeRules = [];
        $this->brands = $brands;
        $this->suppliers = $suppliers;
        $this->unclassifiedBrandId = $unclassifiedBrandId;
        foreach ($rules as $rule) {
            if ($rule['supplier_name'] ?? null) {
                $this->supplierRules[AnalysisWorkbookParser::key($rule['supplier_name'])][] = $rule;
            }
            foreach (array_unique([$rule['brand_code'] ?? null, $rule['brand_name'] ?? null, $rule['brand_zh'] ?? null]) as $alias) {
                if (AnalysisWorkbookParser::key($alias) !== '') {
                    $this->codeRules[AnalysisWorkbookParser::key($alias)][] = $rule;
                }
            }
        }
    }

    /**
     * 分别计算品牌、品类的唯一匹配及快照名称。
     *
     * @param array $row 原始采购记录，含品牌代号、供应商及货号
     * @param array $categoryIds 品类编码到主键映射
     * @return array 可直接写入采购明细的主键、名称、状态及来源证据
     */
    public function classify(array $row, array $categoryIds): array
    {
        $supplierKey = AnalysisWorkbookParser::key($row['supplier_raw'] ?? '');
        $codeKey = AnalysisWorkbookParser::key($row['brand_raw'] ?? '');
        $supplierRules = $this->supplierRules[$supplierKey] ?? [];
        $codeRules = $this->codeRules[$codeKey] ?? [];
        $exactRules = array_values(array_filter($supplierRules, static function (array $rule) use ($codeKey): bool {
            $aliases = array_map([AnalysisWorkbookParser::class, 'key'], [$rule['brand_code'] ?? '', $rule['brand_name'] ?? '', $rule['brand_zh'] ?? '']);

            return $codeKey !== '' && in_array($codeKey, $aliases, true);
        }));
        // 优先采用“代号＋供应商”的配对规则；通用供应商仅提供品类候选。
        $candidateRules = $exactRules ?: $supplierRules;
        $categories = array_values(array_unique(array_column($candidateRules, 'category_code')));
        $productCategories = $this->parser->categoryWords($row['product_description'] ?? '');
        if (count($categories) > 1 && $productCategories !== []) {
            $intersection = array_values(array_intersect($categories, $productCategories));
            if ($intersection !== []) {
                $categories = $intersection;
            }
        }
        if (count($categories) > 1 && $exactRules === [] && $codeRules !== []) {
            $intersection = array_values(array_intersect($categories, array_column($codeRules, 'category_code')));
            if ($intersection !== []) {
                $categories = $intersection;
            }
        }
        $category = count($categories) === 1 ? $categories[0] : 'unclassified';
        $matchingRules = $exactRules ?: $codeRules;
        if ($category !== 'unclassified') {
            $matchingRules = array_values(array_filter($matchingRules, static fn (array $rule): bool => $rule['category_code'] === $category));
        }
        $pending = (bool) array_filter($matchingRules, static fn (array $rule): bool => (bool) ($rule['brand_pending'] ?? false));
        $brandIds = array_values(array_unique(array_filter(array_column($matchingRules, 'brand_id'))));
        $brandId = !$pending && count($brandIds) === 1 ? (int) $brandIds[0] : $this->unclassifiedBrandId;
        $brand = $this->brands[$brandId];
        $categoryNames = AnalysisWorkbookParser::CATEGORIES[$category];
        $categoryStatus = $category !== 'unclassified' ? 'matched' : ($categories === [] ? 'unmatched' : 'ambiguous');
        $brandStatus = $pending ? 'pending' : ($brandId !== $this->unclassifiedBrandId ? 'matched' : (count($brandIds) > 1 ? 'ambiguous' : 'unmatched'));

        return [
            'brand_id' => $brandId, 'brand_name' => $brand['name_zh'], 'brand_name_en' => $brand['name_en'],
            'brand_match_status' => $brandStatus,
            'category_id' => $categoryIds[$category], 'category_code' => $category,
            'category_name' => $categoryNames[0], 'category_name_en' => $categoryNames[1],
            'supplier_id' => $this->suppliers[$supplierKey] ?? null, 'supplier_name' => $row['supplier_raw'] ?? null,
            'classification_status' => $categoryStatus,
            'classification_evidence' => [
                'method' => $exactRules ? 'supplier_and_code' : ($supplierRules ? 'supplier_candidates' : 'unmatched'),
                'category_candidates' => $categories, 'brand_candidates' => $brandIds,
                'product_categories' => $productCategories, 'brand_pending' => $pending,
                'rule_positions' => array_values(array_unique(array_map(static fn (array $rule): string => $rule['sheet_name'] . ':' . $rule['row_number'], $candidateRules))),
            ],
        ];
    }
}
