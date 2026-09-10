<?php

namespace App\Services;

/**
 * 将截图文字整理为可编辑表单建议，沿用原 Invoice 的买卖双方区分及已付总额优先口径。
 * 只返回有文字依据的字段；未知值不覆盖输入框，截图不负责生成内部订单号。
 */
class InvoiceOcrParserService
{
    /**
     * 解析截图或粘贴文本，提取客户、商品、金额与付款状态建议。
     *
     * @param  string  $text  截图识别或粘贴得到的原始文本
     * @return array 识别建议 fields、currency、sourceAmount、paymentStatus 和 warnings
     */
    public function parse(string $text): array
    {
        // Tesseract 的中文输出会在汉字间插空格；旧 PayPal 截图还常把 US$ 读成 USS。
        $text = preg_replace('/(?<=\p{Han})[ \t]+(?=\p{Han})/u', '', $text);
        $text = preg_replace('/\bU[S5][S§＄](?=\s*\d)/iu', 'US$', $text);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text))));
        $text = implode("\n", $lines);
        $fields = $this->customerFields($lines, $text);
        $date = $this->orderDate($text);
        if ($date) {
            $fields['order_date'] = $date;
        }
        $link = $this->capture('~https?://[^\s<>]+~iu', $text, 0);
        if ($link && preg_match('/invoice|invoicing/i', $link)) {
            $fields['invoice_link'] = rtrim($link, '.,;)');
        }

        [$amount, $currency] = $this->paidAmount($lines);
        $warnings = [];
        if ($amount !== null && $currency === 'USD') {
            $fields['amount_usd'] = $amount;
        } elseif ($amount !== null) {
            $warnings[] = 'currency_conversion_required';
        }
        $items = $this->items($lines, $currency);
        if ($items) {
            $fields['items'] = $items;
        }
        $fields = array_merge($fields, $this->options($text, $currency));
        $paymentStatus = $this->paymentStatus($text);
        if ($paymentStatus) {
            $fields['invoice_status'] = $paymentStatus;
        }

        return [
            'fields' => $fields,
            'currency' => $currency,
            'sourceAmount' => $amount,
            'paymentStatus' => $paymentStatus,
            'warnings' => $warnings,
        ];
    }

    /**
     * 提取正则表达式指定捕获组，并去除首尾空白。
     *
     * @param  string  $pattern  包含分隔符的正则表达式
     * @param  string  $text  截图识别或粘贴得到的原始文本
     * @param  int  $group  正则捕获组序号；0 表示完整匹配；默认 1
     * @return string 指定捕获组文本；未匹配返回空字符串
     */
    private function capture(string $pattern, string $text, int $group = 1): string
    {
        return preg_match($pattern, $text, $matches) ? trim($matches[$group] ?? '') : '';
    }

    /**
     * 买方只从 Bill To / Ship To 或明确客户标签取值，不能把卖方邮箱当客户。
     *
     * @param  array  $lines  按视觉顺序拆分并去除空白的文本行
     * @param  string  $text  截图识别或粘贴得到的原始文本
     * @return array 有文本依据的客户姓名、邮箱、电话、国家、地址及收款邮箱建议
     */
    private function customerFields(array $lines, string $text): array
    {
        $fields = [];
        $emailPattern = '[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}';
        $buyerStart = null;
        $buyerLines = [];
        foreach ($lines as $index => $line) {
            if (preg_match('/^(?:bill(?:ed)?\s*to\b|ship(?:ping)?\s*to\b|recipient\b|收件人|账单寄送至|寄送至)[:：\s]*(.*)$/iu', $line, $matches)) {
                $buyerStart = $index;
                if ($matches[1] !== '') {
                    $buyerLines[] = $matches[1];
                }
                for ($next = $index + 1; $next < min(count($lines), $index + 12); $next++) {
                    if (preg_match('/^(?:(?:invoice|date|due|payment|items?|description|quantity|qty|subtotal|total|amount|notes|terms|view)\b|商品|物品|总计|共计)/iu', $lines[$next])) {
                        break;
                    }
                    $buyerLines[] = $lines[$next];
                }
                break;
            }
        }
        $buyerText = implode("\n", $buyerLines);
        $customerEmail = $this->capture('/^(?:customer\s*email|buyer\s*email|客户邮箱|邮箱)\s*[:：]?\s*(' . $emailPattern . ')/imu', $text)
            ?: $this->capture('/(' . $emailPattern . ')/i', $buyerText);
        $sellerEmail = $this->capture('/^(?:receiving\s*paypal|recipient\s*paypal|paypal\s*(?:account|email)|seller\s*email|收款账户|收款账号)\s*[:：]?\s*(' . $emailPattern . ')/imu', $text);
        if (!$sellerEmail && $buyerStart !== null) {
            $sellerEmail = $this->capture('/(' . $emailPattern . ')/i', implode("\n", array_slice($lines, 0, $buyerStart)));
        }
        $name = $this->capture('/^(?:customer(?:\s*full)?\s*name|recipient\s*name|客户全名|收件人姓名)\s*[:：]?\s*(.+)$/imu', $text);
        if (!$name && isset($buyerLines[0]) && !preg_match('/@|\d|^https?:|^(?:email|phone|address|country)\b/i', $buyerLines[0])) {
            $name = $buyerLines[0];
        }
        $phone = $this->capture('/^(?:phone(?:\s*number)?|tel(?:ephone)?|mobile|电话号码|电话|联系电话)\s*[:：]?\s*([+()0-9][()0-9 .\-]{6,}[0-9])/imu', $text);
        if (!$phone) {
            $phone = $this->capture('/(\+\d[\d ().\-]{7,}\d)/', $buyerText);
        }
        $country = $this->capture('/^(?:country(?:\s*\/\s*region)?|国家(?:\s*\/\s*地区)?)\s*[:：]\s*(.+)$/imu', $text);
        if (!$country) {
            $country = $this->capture('/^(United States(?: of America)?|USA|United Kingdom|UK|Canada|Australia|New Zealand|Germany|France|Italy|Spain|Singapore|Japan|China|中国|美国|加拿大|英国|澳大利亚)$/imu', $buyerText);
        }
        $address = $this->capture('/^(?:shipping\s*address|delivery\s*address|address|收货地址|地址)\s*[:：]\s*(.+)$/imu', $text);
        if (!$address && $buyerLines) {
            $addressLines = array_filter($buyerLines, fn ($line) => $line !== $name
                && $line !== $country && $line !== $phone
                && !preg_match('/@|^https?:|^(?:phone|tel|mobile|country)\b/i', $line));
            $address = implode("\n", $addressLines);
        }
        foreach (['customer_full_name' => $name, 'customer_email' => $customerEmail, 'recipient_paypal' => $sellerEmail,
            'phone_number' => $phone, 'country' => $country, 'address' => $address] as $key => $value) {
            if ($value !== '') {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    /**
     * 采用明确下单/开票日期，避免把到期日期当成下单日期。
     *
     * @param  string  $text  截图识别或粘贴得到的原始文本
     * @return string|null 标准 Y-m-d 订单日期；未识别或日期无效时为 null
     */
    private function orderDate(string $text): ?string
    {
        $value = $this->capture('/^(?:order\s*date|date\s*ordered|订单日期|下单日期)\s*[:：]?\s*([^\n]+)/imu', $text)
            ?: $this->capture('/\b(?:invoice\s*date|issued(?:\s*on)?)\s*[:：]\s*([^\n]+)/iu', $text)
            ?: $this->capture('/^(?:invoice\s*date|date(?!\s*(?:added|due)\b)(?:\s*issued)?|issued(?:\s*on)?|开票日期|已发出|开具日期)\s*[:：]?\s*([^\n]+)/imu', $text);
        if (!$value) {
            return null;
        }
        if (preg_match('/\b(20\d{2})\s*[-\/.年]\s*(\d{1,2})\s*[-\/.月]\s*(\d{1,2})(?:日|\b)/u', $value, $parts)) {
            return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
                ? sprintf('%04d-%02d-%02d', $parts[1], $parts[2], $parts[3]) : null;
        }
        if (!preg_match('/\b(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\b/i', $value)) {
            return null;
        }
        $timestamp = strtotime($value);

        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }

    /**
     * 已付金额优先，其次 Invoice Total；不从商品单价或未付余额猜测总额。
     *
     * @param  array  $lines  按视觉顺序拆分并去除空白的文本行
     * @return array 已付或订单总金额与币种二元组，未知部分为 null
     */
    private function paidAmount(array $lines): array
    {
        foreach (['paid\s*total|total\s*paid|amount\s*paid|paid\s*amount|已付(?:款)?(?:总额|金额)', 'invoice\s*total|grand\s*total|order\s*amount|订单金额|total|总计|合计|共计'] as $label) {
            foreach ($lines as $index => $line) {
                if (!preg_match('/^(?:' . $label . ')(?=\s|[:：(（$]|USD|US\$|\d|$)/iu', $line)) {
                    continue;
                }
                $money = $this->money($line) ?? $this->money($lines[$index + 1] ?? '');
                // 原版粘贴表单中的订单金额已明确为美元，可省略货币符号。
                if (!$money && preg_match('/^(?:order\s*amount(?:\s*\(USD\))?|订单金额(?:（美元）)?)\s*[:：]\s*([\d,]+(?:\.\d{1,2})?)$/iu', $line, $matches)) {
                    $money = [(float) str_replace(',', '', $matches[1]), 'USD'];
                }
                if ($money && $money[0] > 0) {
                    return $money;
                }
            }
        }

        return [null, null];
    }

    /**
     * 从文本提取金额及币种，无法识别时返回空值。
     *
     * @param  string  $text  截图识别或粘贴得到的原始文本
     * @return array|null 金额与币种二元组；无法识别时返回 null
     */
    private function money(string $text): ?array
    {
        $number = '([0-9][0-9,]*(?:\.[0-9]{1,2})?)';
        $currency = '(USD|US\$|GBP|EUR|CAD|AUD|NZD|HKD|CNY|JPY|SGD|\$|£|€)';
        if (preg_match('/' . $currency . '\s*' . $number . '/iu', $text, $matches)) {
            [$symbol, $value] = [$matches[1], $matches[2]];
        } elseif (preg_match('/' . $number . '\s*' . $currency . '/iu', $text, $matches)) {
            [$symbol, $value] = [$matches[2], $matches[1]];
        } else {
            return null;
        }
        $symbol = strtoupper($symbol);
        if ($symbol === '$' && preg_match('/\b(CAD|AUD|NZD|HKD|SGD)\b/i', $text, $explicitCurrency)) {
            $symbol = strtoupper($explicitCurrency[1]);
        }

        return [(float) str_replace(',', '', $value), ['US$' => 'USD', '$' => 'USD', '£' => 'GBP', '€' => 'EUR'][$symbol] ?? $symbol];
    }

    /**
     * 读取明细行里的数量、单价和行金额；折扣、运费、总额不进入商品列表。
     *
     * @param  array  $lines  按视觉顺序拆分并去除空白的文本行
     * @param  string|null  $currency  金额币种代码；null 表示尚未识别
     * @return array 识别到的商品明细列表；折扣与运费不生成实物商品
     */
    private function items(array $lines, ?string $currency): array
    {
        $items = [];
        $inTable = false;
        $pending = [];
        for ($index = 0; $index < count($lines); $index++) {
            $line = $lines[$index];
            $line = trim(str_replace('|', ' ', $line));
            // 只跳过表头，不能把“商品包 2 US$100.00”等真实明细当成表头丢弃。
            if (preg_match('/^(?:(?:item(?:s|\s*name)?|description|product(?:\s*name)?|quantity|qty|unit\s*price|price|amount|商品|产品|物品|产品名称|商品名称|名称|数量|单价|价格|金额)\s*)+$/iu', $line)) {
                $inTable = true;
                continue;
            }
            if (preg_match('/^(?:(?:subtotal|total|invoice\s*total|grand\s*total|notes|terms)\b|小计|总计|共计)/iu', $line)) {
                $inTable = false;
                $pending = [];
            }
            if (!$inTable) {
                continue;
            }
            // PayPal 卡片格式：名称与行金额在第一行，下一行是“数量 x 单价”。
            // 必须在旧表格规则前匹配，否则名称末尾的编号会被误读为数量。
            $card = $this->cardItem($lines, $index, $currency);
            if ($card !== null) {
                if ($card['item'] !== null) {
                    $items[] = $card['item'];
                }
                $index += $card['consumed'];
                $pending = [];
                continue;
            }
            if ($this->isAdjustment($line)) {
                $pending = [];
                continue;
            }
            if (preg_match('/^(?:quantity|qty|price|amount|unit\s*price|数量|单价|金额)(?:\s+(?:quantity|qty|price|amount))*$/iu', $line)) {
                continue;
            }
            // 上一件商品的行金额可能单独占一行，不应成为下一件商品名称。
            if (!$pending && preg_match('/^(?:[A-Z]{3}|US\$|\$|£|€)?\s*[\d,]+\.\d{2}(?:\s*[A-Z]{3})?$/iu', $line)) {
                continue;
            }
            $pending[] = $line;
            $pending = array_slice($pending, -8);
            $line = implode(' ', $pending);
            // PayPal 同行格式：商品名  数量  单价  行金额，兼容货币符号前后位置。
            if (!preg_match('/^(.+?)\s+(\d+)\s*(?:(?:[x×]|each|ea|ch|件)\s*)?(?:(USD|US\$|GBP|EUR|CAD|AUD|\$|£|€)\s*)?([\d,]+(?:\.\d{1,2})?)(?:\s*(?:USD|GBP|EUR|CAD|AUD))?(?:\s+(?:[A-Z]{3}|US\$|\$|£|€)?\s*[\d,]+(?:\.\d{1,2})?(?:\s*(?:USD|GBP|EUR|CAD|AUD))?)?$/iu', $line, $matches)) {
                continue;
            }
            if (!preg_match('/[a-z\p{Han}]/iu', $matches[1])) {
                continue;
            }
            $item = [
                'product_name' => trim($matches[1]),
                'description' => trim($matches[1]),
                'quantity' => (int) $matches[2],
            ];
            $itemCurrency = $this->money($line)[1] ?? $currency;
            if (($currency === null || $currency === 'USD') && $itemCurrency === 'USD') {
                $item['price'] = (float) str_replace(',', '', $matches[4]);
            }
            if ($item['quantity'] > 0 && $item['quantity'] <= 10000) {
                $items[] = $item;
                $pending = [];
            }
        }

        return array_slice($items, 0, 100);
    }

    /**
     * 返回卡片商品及已消费行数；抵扣/运费卡片会被消费，但不会生成商品。
     *
     * @param  array  $lines  按视觉顺序拆分并去除空白的文本行
     * @param  int  $index  当前商品卡片在文本行数组中的起始下标
     * @param  string|null  $currency  金额币种代码；null 表示尚未识别
     * @return array|null 商品与已消费行数；不是商品卡片时返回 null
     */
    private function cardItem(array $lines, int $index, ?string $currency): ?array
    {
        $title = $lines[$index];
        $quantityLine = $lines[$index + 1] ?? '';
        $consumed = 1;
        $moneyPattern = '(?:USD|US\$|GBP|EUR|CAD|AUD|NZD|HKD|CNY|JPY|SGD|\$|£|€)\s*-?[\d,]+(?:\.\d{1,2})?';
        // 某些引擎将右侧行金额单独输出，仍需与下面的数量行合并。
        if (preg_match('/^-?\s*' . $moneyPattern . '$/iu', $quantityLine)) {
            $quantityLine = $lines[$index + 2] ?? '';
            $consumed = 2;
        }
        if (!preg_match('/^(\d+)\s*[x×]\s*(.+)$/iu', $quantityLine, $matches)) {
            return null;
        }
        $quantity = (int) $matches[1];
        $unitMoney = $this->money($matches[2]);
        if (!$unitMoney || $quantity < 1 || $quantity > 10000) {
            return null;
        }
        $name = trim(preg_replace('/\s+-?\s*' . $moneyPattern . '$/iu', '', $title));
        if ($this->isAdjustment($name) || preg_match('/-\s*(?:[A-Z]{3}|US\$|\$|£|€|\d)|(?:[A-Z]{3}|US\$|\$|£|€)\s*-/iu', $matches[2])) {
            return ['item' => null, 'consumed' => $consumed];
        }
        if (!preg_match('/[a-z\p{Han}]/iu', $name)) {
            return null;
        }
        $item = ['product_name' => $name, 'description' => $name, 'quantity' => $quantity];
        if (($currency === null || $currency === 'USD') && $unitMoney[1] === 'USD') {
            $item['price'] = $unitMoney[0];
        }

        return ['item' => $item, 'consumed' => $consumed];
    }

    /**
     * 抵扣和运费参与订单金额，不作为实物商品录入。
     *
     * @param  string  $name  当前业务对象的名称
     * @return bool 名称属于折扣、抵扣、运费或包装调整项时为 true
     */
    private function isAdjustment(string $name): bool
    {
        return (bool) preg_match('/^(?:(?:store\s*credit|discount|shipping|delivery|credit|coupon|no\s*box|gift\s*box)\b|折扣|运费|抵扣)/iu', $name);
    }

    /**
     * 识别加急运输、礼盒及折扣等 Invoice 表单选项。
     *
     * @param  string  $text  截图识别或粘贴得到的原始文本
     * @param  string|null  $currency  金额币种代码；null 表示尚未识别
     * @return array 识别到的加急、礼盒、固定折扣和百分比折扣字段
     */
    private function options(string $text, ?string $currency): array
    {
        $fields = [];
        $percentage = $this->capture('/(?:discount|coupon|promo|折扣)\s*[:：(（]?\s*(\d+(?:\.\d+)?)\s*%/iu', $text);
        if ($percentage !== '' && (float) $percentage <= 100) {
            $fields['percentage_discount'] = (float) $percentage;
        }
        $fixed = $this->capture('/(?:fixed\s*discount|discount|coupon|折扣金额)\s*[:：]?\s*((?:USD|US\$|\$)\s*[\d,]+(?:\.\d{2})?)/iu', $text);
        if ($fixed !== '' && $currency === 'USD') {
            $fields['fixed_discount'] = $this->money($fixed)[0];
        }
        if (preg_match('/\b(?:no|without)\s*(?:gift\s*)?box\b/i', $text)) {
            $fields['gift_box'] = 'None';
        } elseif (preg_match('/\bgift\s*box\s*[:：]?\s*(?:yes|has|included)\b/i', $text)) {
            $fields['gift_box'] = 'Has';
        }
        if (preg_match('/^(?:gift\s*box|礼盒)\s*[:：]\s*(yes|has|included|no|none|有|无)/imu', $text, $matches)) {
            $fields['gift_box'] = in_array(strtolower($matches[1]), ['yes', 'has', 'included', '有']) ? 'Has' : 'None';
        }
        if (preg_match('/^加急派送\s*[:：]\s*(是|否)/mu', $text, $matches)) {
            $fields['expedited_shipping'] = $matches[1] === '是';
        }
        if (preg_match('/\b(?:expedited|express|rush|priority)\s*(?:shipping|delivery)\b/i', $text)) {
            $fields['expedited_shipping'] = !preg_match('/\b(?:no|without|not)\s*(?:expedited|express|rush|priority)\s*(?:shipping|delivery)\b|(?:expedited|express)\s*shipping\s*[:：]?\s*no\b/i', $text);
        }

        return $fields;
    }

    /**
     * 将截图付款状态映射到 Invoice 表单支持的状态枚举。
     *
     * @param  string  $text  截图识别或粘贴得到的原始文本
     * @return string|null Paid、Pending、Overdue、Unpaid、Refunded 或 Failed；未知为 null
     */
    private function paymentStatus(string $text): ?string
    {
        // 优先采用明确状态，避免将 Pending、Failed 等遗漏后保留表单旧的 Paid。
        if (preg_match('/^(?:invoice\s*status|payment\s*status|status|Invoice状态|付款状态)\s*[:：]?\s*(Paid|Pending|Overdue|Unpaid|Refunded|Failed)\s*$/imu', $text, $matches)) {
            return ucfirst(strtolower($matches[1]));
        }
        foreach (['Refunded', 'Failed', 'Overdue', 'Pending', 'Unpaid'] as $status) {
            if (preg_match('/^' . $status . '\s*$/imu', $text)) {
                return $status;
            }
        }
        if (preg_match('/\b(?:unpaid|not\s*paid|partially\s*paid|payment\s*pending)\b/i', $text)) {
            return 'Unpaid';
        }
        if (preg_match('/\brefunded\b/i', $text)) {
            return 'Refunded';
        }

        return preg_match('/^(?:invoice\s*status\s*[:：]?\s*|status\s*[:：]?\s*)?paid\s*$/imu', $text) ? 'Paid' : null;
    }
}
