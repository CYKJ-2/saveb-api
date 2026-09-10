<?php

namespace App\Services;

use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

/** 适配金山表格的跨月字段差异，始终保留原文供追溯。 */
class AnalysisWorkbookParser
{
    public const CATEGORIES = [
        'bags' => ['包包', 'Bags'],
        'jewelry' => ['珠宝', 'Jewelry'],
        'shoes' => ['鞋子', 'Shoes'],
        'watches' => ['手表', 'Watches'],
        'clothing' => ['衣服', 'Clothing'],
        'hats' => ['帽子', 'Hats'],
        'eyewear' => ['眼镜', 'Eyewear'],
        'belts' => ['皮带', 'Belts'],
        'scarves' => ['丝巾', 'Scarves'],
    ];

    /**
     * 规范化匹配文本；不做模糊匹配，不把相近供应商名称自动合并。
     *
     * @param mixed $value 原始单元格值
     * @return string 去除空白、统一宽度和大小写后的匹配键
     */
    public static function key(mixed $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', mb_convert_kana(trim((string) $value), 'as', 'UTF-8')) ?? '');
    }

    /**
     * 从商品描述提取明确的品类词；多种品类同时出现时全部保留为候选。
     *
     * @param string $text 来源描述或服饰表中的类别名称
     * @return array<int, string> 品类 code；无明确关键词时为空
     */
    public function categoryWords(string $text): array
    {
        $patterns = [
            'bags' => '/包包|手提包|手袋|钱包|背包|单肩包|斜挎包|\b(?:bags?|handbags?|wallets?|backpacks?)\b/iu',
            'jewelry' => '/珠宝|首饰|手链|项链|戒指|耳环|耳钉|手镯|胸针|\b(?:jewelry|jewellery|bracelets?|necklaces?|rings?|earrings?)\b/iu',
            'shoes' => '/鞋|运动鞋|高跟鞋|靴|\b(?:shoes?|sneakers?|heels?|boots?|sandals?)\b/iu',
            'watches' => '/手表|腕表|\b(?:watch|watches)\b/iu',
            'clothing' => '/衣服|服装|短袖|长袖|短裤|长裤|外套|衬衫|裙|毛衣|卫衣|\b(?:clothing|shirts?|t-shirts?|jackets?|dresses|dress|pants|sweaters?|hoodies?)\b/iu',
            'hats' => '/帽|\b(?:hats?|caps?)\b/iu',
            'eyewear' => '/眼镜|\b(?:glasses|sunglasses|eyewear)\b/iu',
            'belts' => '/皮带|腰带|\bbelts?\b/iu',
            'scarves' => '/丝巾|围巾|\b(?:scarf|scarves)\b/iu',
        ];

        return array_keys(array_filter($patterns, static fn (string $pattern): bool => preg_match($pattern, $text) === 1));
    }

    /**
     * 把供应商优选表展开为品牌、品类、供应商对应关系。
     *
     * @param array $sheet 工作表名称及原始行；服饰混合表按行内容拆分类别
     * @return array<int, array> 每个供应商候选关系及来源行；品牌代号允许重复
     */
    public function supplierRules(array $sheet): array
    {
        $sheetCategories = $this->categoryWords($sheet['name']);
        if ($sheetCategories === []) {
            throw ValidationException::withMessages(['file' => 'Unknown supplier worksheet: ' . $sheet['name']]);
        }
        $mixedCategories = count($sheetCategories) > 1;
        $rules = [];
        foreach ($sheet['rows'] as $row) {
            $cells = $row['cells'];
            $first = trim($cells['A'] ?? '');
            if ($row['number'] <= 1 || $first === '' || self::key($first) === '品牌') {
                continue;
            }
            $categories = $mixedCategories ? $this->categoryWords($first) : $sheetCategories;
            $rowTypes = $this->categoryWords($cells['C'] ?? '');
            $isCategoryRow = $mixedCategories || ($rowTypes !== [] && empty($cells['B']));
            $brandName = $isCategoryRow ? null : (trim($cells['B'] ?? '') ?: $first);
            $brandZh = $isCategoryRow ? null : $first;
            $code = $isCategoryRow ? null : trim($cells['C'] ?? '');
            // 即使品牌暂未填写供应商，也保留品牌与品类对应关系。
            if ($brandName) {
                foreach ($categories as $category) {
                    $rules[] = [
                        'sheet_name' => $sheet['name'], 'row_number' => $row['number'],
                        'category_code' => $category, 'brand_name' => $brandName,
                        'brand_zh' => $brandZh, 'brand_code' => $code,
                        'supplier_name' => null, 'preference' => 0, 'raw' => $row,
                    ];
                }
            }
            foreach ($cells as $column => $supplierCell) {
                $columnNumber = $this->columnNumber($column);
                if ($columnNumber < ($mixedCategories ? 2 : 4)) {
                    continue;
                }
                foreach (preg_split('/[\r\n、]+/u', $supplierCell) ?: [] as $supplier) {
                    $supplier = trim($supplier);
                    if ($supplier === '' || in_array(self::key($supplier), ['无', '暂无', '/', '-', '供应商', '品牌'], true)) {
                        continue;
                    }
                    foreach ($categories as $category) {
                        $rules[] = [
                            'sheet_name' => $sheet['name'],
                            'row_number' => $row['number'],
                            'category_code' => $category,
                            'brand_name' => $brandName,
                            'brand_zh' => $brandZh,
                            'brand_code' => $code,
                            'supplier_name' => $supplier,
                            'preference' => $columnNumber - ($mixedCategories ? 1 : 3),
                            'raw' => $row,
                        ];
                    }
                }
            }
        }

        return $rules;
    }

    /**
     * 解析一个月份的采购记录；空值、混合价格和缺失日期保留为待核对项。
     *
     * @param array $sheet 工作表原始数据，包含 date1904 日期系统标志
     * @param string|null $currency 已确认的 ISO 币种；null 表示待确认，不进行币种转换
     * @param string $priceBasis row_total 表示每行合计，unit 表示单件价，unknown 表示口径待确认
     * @return array<int, array> 可入库的采购商品行，尚未做供应商品类和销售订单关联
     */
    public function procurementRows(array $sheet, ?string $currency, string $priceBasis): array
    {
        $header = $this->header($sheet);
        $records = [];
        foreach ($sheet['rows'] as $row) {
            if ($row['number'] <= $header['number']) {
                continue;
            }
            $value = static fn (string $field): string => trim($row['cells'][$header['columns'][$field] ?? ''] ?? '');
            if ($value('supplier') === '供应商' || implode('', [$value('reference'), $value('product'), $value('supplier'), $value('brand')]) === '') {
                continue;
            }
            $issues = $header['issues'];
            $reference = $value('reference');
            $recordType = preg_match('/invoice/i', $reference) ? 'invoice' : 'ordinary';
            if (preg_match('/售后|补发|换货/u', $reference)) {
                $recordType = 'after_sale';
            } elseif (preg_match('/网红采购|达人采购|样品|赠品/u', $reference)) {
                $recordType = 'other_procurement';
            }
            preg_match('/^(?:(?:WS|PL|invoice)\s*[-#:]?\s*|#\s*)?(\d+)$/i', $reference, $orderNumber);
            $price = $this->decimal($value('price'), 2);
            $quantity = $this->decimal($value('quantity'), 4);
            $priceStatus = $value('price') === '' ? 'missing' : ($price === null ? 'invalid' : 'valid');
            if ($price === null) {
                $issues[] = 'price_' . $priceStatus;
            }
            preg_match('/^(USD|CNY|RMB|US\$|[$¥￥])/iu', trim($value('price')), $priceCurrency);
            $explicitCurrency = isset($priceCurrency[1]) ? (in_array(strtoupper($priceCurrency[1]), ['CNY', 'RMB', '¥', '￥'], true) ? 'CNY' : 'USD') : null;
            if ($currency !== null && $explicitCurrency !== null && $currency !== $explicitCurrency) {
                $priceStatus = 'currency_conflict';
                $issues[] = 'price_currency_conflict';
            }
            if ($currency === null) {
                $issues[] = 'currency_unknown';
            }
            $amount = null;
            if ($priceStatus === 'valid' && $currency !== null) {
                if ($priceBasis === 'row_total') {
                    $amount = $price;
                } elseif ($priceBasis === 'unit' && $quantity !== null && bccomp($quantity, '0', 4) > 0) {
                    $amount = bcadd(bcmul($price, $quantity, 4), bccomp($price, '0', 2) >= 0 ? '0.005' : '-0.005', 2);
                }
            }
            if ($amount === null && $price !== null) {
                $issues[] = 'amount_basis_incomplete';
            }
            $orderDate = $this->date($value('order_date'), (bool) $sheet['date1904']);
            $procurementDate = $this->date($value('purchase_date'), (bool) $sheet['date1904']);
            $legacyDate = $this->date($value('legacy_date'), (bool) $sheet['date1904']);
            $analysisDate = $orderDate ?? $procurementDate ?? $legacyDate;
            $dateBasis = $orderDate ? 'customer_order_date' : ($procurementDate ? 'procurement_date' : ($legacyDate ? 'legacy_date' : 'unknown'));
            if (!$orderDate) {
                $issues[] = 'customer_order_date_missing';
            }
            if (!$analysisDate) {
                $issues[] = 'analysis_date_missing';
            }
            $cancelled = preg_match('/取消|cancel/i', $value('status') . ' ' . $value('shipping_reason')) === 1;
            $records[] = [
                'sheet_name' => $sheet['name'],
                'row_number' => $row['number'],
                'row_hash' => hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                'raw' => $row,
                'customer_order_date' => $orderDate,
                'procurement_date' => $procurementDate ?? $legacyDate,
                'analysis_date' => $analysisDate,
                'date_basis' => $dateBasis,
                'order_reference' => $reference ?: null,
                'order_number' => $orderNumber[1] ?? null,
                'record_type' => $recordType,
                'brand_raw' => $value('brand') ?: null,
                'product_description' => $value('product') ?: null,
                'supplier_raw' => $value('supplier') ?: null,
                'customer_name' => $value('customer') ?: null,
                'price_raw' => $value('price') === '' ? null : $value('price'),
                'price_column' => $header['price_label'],
                'actual_price' => $price,
                'quantity' => $quantity,
                'analysis_amount' => $amount,
                'currency' => $currency,
                'price_basis' => $priceBasis,
                'price_status' => $priceStatus,
                'purchase_status' => $value('status') ?: null,
                'is_cancelled' => $cancelled,
                'issues' => array_values(array_unique($issues)),
            ];
        }

        return $records;
    }

    /**
     * 根据字段含义识别表头；六月品牌列标题异常时仅作带提示的相邻列兼容。
     *
     * @param array $sheet 来源工作表
     * @return array{number: int, columns: array, issues: array, price_label: string|null} 字段到列的映射
     */
    private function header(array $sheet): array
    {
        $aliases = [
            'order_date' => ['顾客下单日期', '客户下单日期'],
            'purchase_date' => ['发起采购日期', '采购日期'],
            'legacy_date' => ['日期'],
            'brand' => ['品牌', '产品名'],
            'product' => ['货号', '产品名称', '商品名称'],
            'supplier' => ['供应商'],
            'customer' => ['客户名', '客户姓名', '顾客姓名'],
            'quantity' => ['数量', '商品数量', '采购数量'],
            'status' => ['是否采购', '采购情况'],
        ];
        foreach (array_slice($sheet['rows'], 0, 10) as $row) {
            $columns = [];
            $priceLabel = null;
            foreach ($row['cells'] as $column => $text) {
                $key = self::key($text);
                foreach ($aliases as $field => $labels) {
                    if (in_array($key, $labels, true)) {
                        $columns[$field] = $column;
                    }
                }
                if (str_starts_with($key, '下单形式') || in_array($key, ['订单号', '订单编号'], true)) {
                    $columns['reference'] = $column;
                }
                if (in_array($key, ['实际成交价格', '实际成交价'], true) || ($key === '价格' && !isset($columns['price']))) {
                    $columns['price'] = $column;
                    $priceLabel = trim($text);
                }
                if (str_contains($key, '未发货原因') || str_contains($key, '则必填原因')) {
                    $columns['shipping_reason'] = $column;
                }
            }
            if (isset($columns['product'], $columns['supplier'], $columns['reference'])) {
                $issues = [];
                if (!isset($columns['brand'])) {
                    $productIndex = $this->columnNumber($columns['product']);
                    foreach (array_keys($row['cells']) as $column) {
                        if ($this->columnNumber($column) === $productIndex - 1 && !in_array($column, $columns, true)) {
                            $columns['brand'] = $column;
                            $issues[] = 'brand_header_inferred';
                        }
                    }
                }
                if ($priceLabel === '价格') {
                    $issues[] = 'legacy_price_column';
                }

                return ['number' => $row['number'], 'columns' => $columns, 'issues' => $issues, 'price_label' => $priceLabel];
            }
        }

        throw ValidationException::withMessages(['file' => 'Unrecognized procurement headers: ' . $sheet['name']]);
    }

    /**
     * 解析单个十进制价格；算式、多个金额、单位混写不会被截断为第一个数字。
     *
     * @param string $raw 来源价格或数量文本
     * @param int $scale 返回的小数位数
     * @return string|null 十进制字符串；空值或含义不明确时返回 null
     */
    public function decimal(string $raw, int $scale): ?string
    {
        $text = trim(mb_convert_kana($raw, 'as', 'UTF-8'));
        $text = preg_replace('/^(?:US\$|USD|CNY|RMB|[¥￥$])\s*/iu', '', $text) ?? '';
        if (!preg_match('/^[+-]?(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d+)?$/', $text)) {
            return null;
        }
        $text = str_replace(',', '', $text);
        if (bccomp($text, '999999999999.99', 4) > 0 || bccomp($text, '-999999999999.99', 4) < 0) {
            return null;
        }
        $rounding = '0.' . str_repeat('0', $scale) . '5';

        return bcadd($text, str_starts_with($text, '-') ? '-' . $rounding : $rounding, $scale);
    }

    /**
     * 解析完整日期或 Excel 序号，不擅自给缺失年份的日期补年。
     *
     * @param string $raw 原日期文本或 Excel 数值
     * @param bool $date1904 是否使用 Excel 1904 日期系统
     * @return string|null YYYY-MM-DD；无法可靠判断时为空
     */
    public function date(string $raw, bool $date1904 = false): ?string
    {
        $text = trim($raw);
        if (preg_match('/^\d{4}[-.\/年]\s*\d{1,2}[-.\/月]\s*\d{1,2}日?(?:\s+.*)?$/u', $text)) {
            preg_match('/^(\d{4})[-.\/年]\s*(\d{1,2})[-.\/月]\s*(\d{1,2})/u', $text, $parts);
            if (checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                return sprintf('%04d-%02d-%02d', $parts[1], $parts[2], $parts[3]);
            }
        }
        if (is_numeric($text) && (float) $text >= 20000 && (float) $text <= 100000) {
            return (new DateTimeImmutable($date1904 ? '1904-01-01' : '1899-12-30'))->modify('+' . (int) floor((float) $text) . ' days')->format('Y-m-d');
        }

        return null;
    }

    /**
     * 把列字母转换为从 1 开始的列号。
     *
     * @param string $column XLSX 列字母，例如 A、AF
     * @return int 对应列号
     */
    private function columnNumber(string $column): int
    {
        $number = 0;
        foreach (str_split($column) as $character) {
            $number = $number * 26 + ord($character) - 64;
        }

        return $number;
    }
}
