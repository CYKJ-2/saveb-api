<?php

namespace App\Services;

use DateTimeImmutable;
use Illuminate\Validation\ValidationException;
use Normalizer;

/** 采购 Excel 字段适配；只依赖采购表和供应商表，不执行公式。 */
class AnalysisWorkbookParser
{
    public const CATEGORIES = [
        'bags' => ['包包', 'Bags'], 'jewelry' => ['珠宝', 'Jewelry'],
        'shoes' => ['鞋子', 'Shoes'], 'watches' => ['手表', 'Watches'],
        'clothing' => ['衣服', 'Clothing'], 'hats' => ['帽子', 'Hats'],
        'eyewear' => ['眼镜', 'Eyewear'], 'belts' => ['皮带', 'Belts'],
        'scarves' => ['丝巾', 'Scarves'], 'unclassified' => ['待分类', 'Unclassified'],
    ];

    /**
     * 规范化代号、姓名及供应商文本，不进行模糊合并。
     *
     * @param mixed $value 原始字段值
     * @return string 统一宽度、大小写和连续空格后的匹配键
     */
    public static function key(mixed $value): string
    {
        $normalized = Normalizer::normalize((string) $value, Normalizer::FORM_KC) ?: (string) $value;

        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($normalized)) ?? '');
    }

    /**
     * 生成客户名称匹配键；不拆分斜杠，不把空客户汇总成同一人。
     *
     * @param mixed $value 原始客户名
     * @return string|null 标准化姓名，空值及占位符返回 null
     */
    public static function customerKey(mixed $value): ?string
    {
        $key = self::key($value);

        return in_array($key, ['', '-', '—', '/', '无', '未知', 'unknown', 'n/a'], true) ? null : $key;
    }

    /**
     * 识别采购月份，作为快照替换边界。
     *
     * @param string $name Sheet 名，如 2026.09、2026-09、2026年9月
     * @return string|null YYYY-MM；不属于有效月表时返回 null
     */
    public function period(string $name): ?string
    {
        if (!preg_match('/^\s*(\d{4})[.\/\-年](\d{1,2})月?\s*$/u', $name, $matches)) {
            return null;
        }

        return checkdate((int) $matches[2], 1, (int) $matches[1])
            ? sprintf('%04d-%02d', $matches[1], $matches[2]) : null;
    }

    /**
     * 在供应商规则的候选品类中使用商品描述消歧，不凭关键词创造供应关系。
     *
     * @param string $text 原始商品描述
     * @return array<int, string> 明确出现的品类编码
     */
    public function categoryWords(string $text): array
    {
        $patterns = [
            'bags' => '/包包|手提包|手袋|钱包|背包|单肩包|斜挎包|\b(?:bags?|handbags?|wallets?|backpacks?)\b/iu',
            'jewelry' => '/珠宝|首饰|手链|项链|戒指|耳环|耳钉|手镯|胸针|\b(?:jewelry|jewellery|bracelets?|necklaces?|rings?|earrings?)\b/iu',
            'shoes' => '/鞋|靴|\b(?:shoes?|sneakers?|heels?|boots?|sandals?)\b/iu',
            'watches' => '/手表|腕表|\b(?:watch|watches)\b/iu',
            'clothing' => '/衣服|服装|短袖|长袖|短裤|长裤|外套|衬衫|裙|毛衣|卫衣|羽绒|皮草|\b(?:clothing|shirts?|t-shirts?|jackets?|dresses|dress|pants|sweaters?|hoodies?)\b/iu',
            'hats' => '/帽子|棒球帽|渔夫帽|礼帽|针织帽|贝雷帽|鸭舌帽|\b(?:hats?|caps?)\b/iu',
            'eyewear' => '/眼镜|墨镜|太阳镜|\b(?:glasses|sunglasses|eyewear)\b/iu',
            'belts' => '/皮带|腰带|\bbelts?\b/iu',
            'scarves' => '/丝巾|\b(?:scarf|scarves)\b/iu',
        ];

        return array_keys(array_filter($patterns, static fn (string $pattern): bool => preg_match($pattern, $text) === 1));
    }

    /**
     * 以 Sheet 名确定品类，只读取供应商列；待确认品牌不创建猜测名称。
     *
     * @param array $sheet 原始工作表，含 name、rows 和 date1904
     * @return array<int, array> 供应关系、品牌定义、原始代号和来源位置
     */
    public function supplierRules(array $sheet): array
    {
        $categoryCode = null;
        foreach (self::CATEGORIES as $code => $names) {
            if (trim($sheet['name']) === $names[0] && $code !== 'unclassified') {
                $categoryCode = $code;
            }
        }
        if (!$categoryCode) {
            throw ValidationException::withMessages(['file' => '无法识别供应商品类 Sheet：' . $sheet['name']]);
        }
        $header = $sheet['rows'][0] ?? ['cells' => [], 'number' => 1];
        $supplierColumns = [];
        $statusColumn = null;
        $codeColumn = null;
        foreach ($header['cells'] as $column => $label) {
            if (str_contains($label, '供应商') && !str_contains($label, '备注')) {
                $supplierColumns[] = $column;
            }
            if ($label === '确认状态') {
                $statusColumn = $column;
            }
            if ($label === '代号') {
                $codeColumn = $column;
            }
        }
        // 原鞋子表 G/H 列无标题但有供应商，I 列是备注。
        if ($categoryCode === 'shoes') {
            $supplierColumns = array_unique(array_merge($supplierColumns, ['D', 'E', 'F', 'G', 'H']));
        }
        $rules = [];
        foreach ($sheet['rows'] as $row) {
            if ($row['number'] <= $header['number']) {
                continue;
            }
            $cells = $row['cells'];
            $first = trim($cells['A'] ?? '');
            if ($first === '') {
                continue;
            }
            $general = $first === $sheet['name'] || str_starts_with($first, '通用供应商');
            if (in_array($first, array_column(self::CATEGORIES, 0), true) && !$general) {
                continue;
            }
            $status = trim($cells[$statusColumn ?? ''] ?? '');
            $pending = !$general && ($first === '待确认' || str_contains($status, '品牌待确认') || str_contains($status, '品牌/'));
            $rule = [
                'category_code' => $categoryCode,
                'brand_name' => $general || $pending ? null : (trim($cells['B'] ?? '') ?: $first),
                'brand_zh' => $general || $pending ? null : $first,
                'brand_code' => $general ? null : (trim($cells[$codeColumn ?? 'C'] ?? '') ?: null),
                'brand_pending' => $pending,
                'relationship_pending' => str_contains($status, '供应关系待确认'),
                'supplier_name' => null, 'sheet_name' => $sheet['name'],
                'row_number' => $row['number'], 'preference' => 0, 'raw' => $row,
            ];
            if (!$general) {
                $rules[] = $rule;
            }
            foreach ($supplierColumns as $index => $column) {
                foreach (preg_split('/[\r\n、]+/u', $cells[$column] ?? '') ?: [] as $supplier) {
                    $supplier = trim($supplier);
                    // “品牌”是此工作簿中实际存在的供应商名称，不能当表头丢弃。
                    if (in_array(self::key($supplier), ['', '无', '暂无', '/', '-', '供应商'], true)) {
                        continue;
                    }
                    $rules[] = array_replace($rule, ['supplier_name' => $supplier, 'preference' => $index + 1]);
                }
            }
        }

        return $rules;
    }

    /**
     * 保留所有非空采购记录，金额仅累计“已采购＋有效实际成交价格”。
     *
     * @param array $sheet 原始采购月表
     * @param string|null $currency 用户确认的币种，本功能使用 CNY
     * @param string $priceBasis row_total 直接使用原字段；不会从货号推算数量
     * @return array<int, array> 待分类入库记录；无效价格保持 null，残缺行保留 issues
     */
    public function procurementRows(array $sheet, ?string $currency, string $priceBasis): array
    {
        $header = $this->header($sheet);
        $period = $this->period($sheet['name']);
        if (!$period) {
            throw ValidationException::withMessages(['file' => '采购 Sheet 必须按月份命名：' . $sheet['name']]);
        }
        $records = [];
        foreach ($sheet['rows'] as $row) {
            if ($row['number'] <= $header['number']) {
                continue;
            }
            $value = static fn (string $field): string => trim($row['cells'][$header['columns'][$field] ?? ''] ?? '');
            if ($value('supplier') === '供应商' && $value('product') === '货号') {
                continue;
            }
            if (!array_filter(array_keys($header['columns']), static fn (string $field): bool => $value($field) !== '')) {
                continue;
            }
            $issues = $header['issues'];
            $price = $this->decimal($value('price'), 2);
            $priceStatus = $value('price') === '' ? 'missing' : ($price === null ? 'invalid' : 'valid');
            if ($priceStatus !== 'valid') {
                $issues[] = 'price_' . $priceStatus;
            }
            preg_match('/^(USD|CNY|RMB|US\$|[$¥￥])/iu', $value('price'), $explicit);
            $explicitCurrency = isset($explicit[1]) ? (in_array(strtoupper($explicit[1]), ['CNY', 'RMB', '¥', '￥'], true) ? 'CNY' : 'USD') : null;
            if ($explicitCurrency && $currency && $explicitCurrency !== $currency) {
                $priceStatus = 'currency_conflict';
                $issues[] = 'price_currency_conflict';
            }
            $procurementDate = $this->date($value('purchase_date'), (bool) $sheet['date1904']);
            $orderDate = $this->date($value('order_date'), (bool) $sheet['date1904']);
            $customerKey = self::customerKey($value('customer'));
            foreach (['procurement_date_missing' => !$procurementDate, 'customer_missing' => !$customerKey, 'brand_code_missing' => $value('brand') === '', 'supplier_missing' => $value('supplier') === ''] as $issue => $missing) {
                if ($missing) {
                    $issues[] = $issue;
                }
            }
            if ($procurementDate && substr($procurementDate, 0, 7) !== $period) {
                $issues[] = 'date_outside_sheet';
            }
            $status = $value('status');
            $eligible = self::key($status) === '已采购' && $priceStatus === 'valid' && $currency !== null && $priceBasis === 'row_total';
            $method = $this->purchaseMethod($value('reference'));
            $records[] = [
                'source_period' => $period, 'sheet_name' => $sheet['name'], 'row_number' => $row['number'],
                'row_hash' => hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 'raw' => $row,
                'customer_order_date' => $orderDate, 'procurement_date' => $procurementDate,
                'analysis_date' => $procurementDate, 'date_basis' => $procurementDate ? 'procurement_date' : 'unknown',
                'order_reference' => $value('reference') ?: null, 'purchase_method' => $method,
                'record_type' => $method === 'invoice' ? 'invoice' : ($method === 'after_sale' ? 'after_sale' : (in_array($method, ['influencer', 'accessory'], true) ? 'other_procurement' : 'ordinary')),
                'brand_raw' => $value('brand') ?: null, 'product_description' => $value('product') ?: null,
                'supplier_raw' => $value('supplier') ?: null, 'customer_name' => $value('customer') ?: null,
                'customer_key' => $customerKey, 'customer_type' => 'unknown',
                'price_raw' => $value('price') === '' ? null : $value('price'), 'price_column' => $header['price_label'],
                'actual_price' => $price, 'supplier_quote' => $this->decimal($value('quote'), 2),
                'analysis_amount' => $eligible ? $price : null, 'quantity' => null,
                'currency' => $currency, 'price_basis' => $priceBasis, 'price_status' => $priceStatus,
                'purchase_status' => $status ?: null, 'is_cancelled' => preg_match('/取消|cancel/i', $status) === 1,
                'is_eligible' => $eligible, 'is_current' => false, 'issues' => array_values(array_unique($issues)),
            ];
        }

        return $records;
    }

    /**
     * 依据明确标记区分采购方式，纯编号保持 unknown。
     *
     * @param string $reference 原始下单形式／单号
     * @return string ws、pl、invoice、after_sale、influencer、accessory 或 unknown
     */
    public function purchaseMethod(string $reference): string
    {
        $reference = self::key($reference);
        if (preg_match('/售后|补发|换货/u', $reference)) {
            return 'after_sale';
        }
        foreach (['invoice' => '/invoice/i', 'ws' => '/^ws(?:[\s\-#:]|\d)/i', 'pl' => '/^pl(?:[\s\-#:]|\d)/i', 'influencer' => '/网红|达人|样品|赠品/u', 'accessory' => '/配件/u'] as $method => $pattern) {
            if (preg_match($pattern, $reference)) {
                return $method;
            }
        }

        return 'unknown';
    }

    /**
     * 根据表头名称兼容各月份的列顺序和旧字段名称。
     *
     * @param array $sheet 原始采购月表
     * @return array{number: int, columns: array, issues: array, price_label: string|null} 字段位置及旧价格列提示
     */
    private function header(array $sheet): array
    {
        $aliases = [
            'order_date' => ['顾客下单日期', '客户下单日期'],
            'purchase_date' => ['发起采购日期', '采购日期', '日期'],
            'brand' => ['品牌', '品牌名', '产品名'], 'product' => ['货号', '产品名称', '商品名称'],
            'supplier' => ['供应商'], 'customer' => ['客户名', '客户姓名', '顾客姓名'],
            'status' => ['是否采购', '采购情况'], 'quote' => ['供应商定价'],
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
            }
            if (isset($columns['product'], $columns['supplier'], $columns['reference'], $columns['status'], $columns['price'], $columns['purchase_date'])) {
                return ['number' => $row['number'], 'columns' => $columns, 'issues' => $priceLabel === '价格' ? ['legacy_price_column'] : [], 'price_label' => $priceLabel];
            }
        }

        throw ValidationException::withMessages(['file' => '无法识别采购表头：' . $sheet['name']]);
    }

    /**
     * 解析单个十进制金额，错误单元格和算式保持无效。
     *
     * @param string $raw 原始金额文本
     * @param int $scale 返回小数位数
     * @return string|null 十进制字符串；无法解释的价格返回 null
     */
    public function decimal(string $raw, int $scale): ?string
    {
        $text = preg_replace('/^(?:US\$|USD|CNY|RMB|[¥￥$])\s*/iu', '', trim(mb_convert_kana($raw, 'as', 'UTF-8'))) ?? '';
        if (!preg_match('/^[+-]?(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d+)?$/D', $text)) {
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
     * 解析完整日期或 Excel 序号，不从 Sheet 名猜测缺失日期。
     *
     * @param string $raw 原始日期文本或数值
     * @param bool $date1904 是否使用 Excel 1904 日期系统
     * @return string|null YYYY-MM-DD；非法日期返回 null
     */
    public function date(string $raw, bool $date1904 = false): ?string
    {
        if (preg_match('/^(\d{4})[-.\/年]\s*(\d{1,2})[-.\/月]\s*(\d{1,2})日?(?:\s+.*)?$/u', trim($raw), $parts)) {
            return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? sprintf('%04d-%02d-%02d', $parts[1], $parts[2], $parts[3]) : null;
        }
        if (is_numeric($raw) && (float) $raw >= 20000 && (float) $raw <= 100000) {
            return (new DateTimeImmutable($date1904 ? '1904-01-01' : '1899-12-30'))->modify('+' . (int) floor((float) $raw) . ' days')->format('Y-m-d');
        }

        return null;
    }
}
