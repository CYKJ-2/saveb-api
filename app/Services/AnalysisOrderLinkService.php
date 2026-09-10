<?php

namespace App\Services;

use App\Dao\AnalysisDao;
use DateTimeImmutable;

/** 使用单号及可验证的姓名、日期关联销售订单；同名或模糊单号不会自动关联。 */
class AnalysisOrderLinkService
{
    private array $candidates = [];

    private array $customerTypes = [];

    /**
     * 注入订单字段查询层。
     *
     * @param AnalysisDao $analysisDao 只读取关联与历史判定所需字段的 DAO
     * @return void 初始化依赖
     */
    public function __construct(private AnalysisDao $analysisDao)
    {
    }

    /**
     * 一次加载关联索引和全部已知历史的首购顺序，日期筛选不会重置首购。
     *
     * @return void 建立本次导入使用的内存索引
     */
    public function load(): void
    {
        $this->candidates = [];
        $this->customerTypes = [];
        $sourceFields = [];
        foreach ($this->analysisDao->sourceCustomerFields() as $source) {
            $sourceFields[$source->order_id][] = ['email' => $this->email($source->email), 'country' => $this->country($source->country)];
        }
        $history = [];
        foreach ($this->analysisDao->ordinaryOrders() as $order) {
            if (str_contains(strtolower((string) $order->classification), 'invoice')) {
                continue;
            }
            $fields = $sourceFields[$order->order_id] ?? [];
            $emails = array_values(array_unique(array_filter(array_column($fields, 'email'))));
            $countries = array_values(array_unique(array_filter(array_column($fields, 'country'))));
            $timestamp = $order->order_time?->copy()->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s');
            $candidate = [
                'id' => $order->id,
                'type' => 'ordinary',
                'key' => 'ordinary:' . $order->id,
                'name' => AnalysisWorkbookParser::key($order->customer_name),
                'date' => $timestamp ? substr($timestamp, 0, 10) : null,
                'timestamp' => $timestamp,
                'precise_time' => true,
                'country' => count($countries) === 1 ? $countries[0] : null,
                'email' => count($emails) === 1 ? $emails[0] : null,
                'valid' => strtolower((string) $order->order_status) === 'completed' && !preg_match('/\btest/i', (string) $order->customer_name),
            ];
            $this->candidates['ordinary'][(string) ($order->client_order_id ?: $order->order_id)][] = $candidate;
            if ($candidate['valid'] && $candidate['email'] && $candidate['date']) {
                $history[$candidate['email']][] = $candidate;
            }
        }
        foreach ($this->analysisDao->invoiceOrders() as $invoice) {
            $rawDate = $invoice->order_date ?: $invoice->invoice_date;
            $date = $rawDate ? (new DateTimeImmutable((string) $rawDate))->format('Y-m-d') : null;
            $candidate = [
                'id' => $invoice->id,
                'type' => 'invoice',
                'key' => 'invoice:' . $invoice->id,
                'name' => AnalysisWorkbookParser::key($invoice->customer_full_name),
                'date' => $date,
                'timestamp' => $date ? $date . ' 00:00:00' : null,
                'precise_time' => false,
                'country' => $this->country($invoice->country),
                'email' => $this->email($invoice->customer_email),
                'valid' => in_array(strtolower((string) $invoice->invoice_status), ['paid', 'completed'], true),
            ];
            $this->candidates['invoice'][(string) $invoice->order_number][] = $candidate;
            if ($candidate['valid'] && $candidate['email'] && $date) {
                $history[$candidate['email']][] = $candidate;
            }
        }
        $this->buildCustomerTypes($history);
    }

    /**
     * 关联具有明确订单号码的记录，拒绝跨网站重复号和姓名、日期冲突。
     *
     * @param array $row 采购记录；只有 customer_order_date 可作为销售日期校验，采购日期不可替代
     * @return array 关联状态、订单主键、国家及已知历史中的顾客类型
     */
    public function link(array $row): array
    {
        $result = ['linked_order_type' => null, 'linked_order_id' => null, 'order_match_status' => 'unmatched', 'country' => null, 'customer_type' => 'unknown'];
        if (empty($row['order_number']) || !in_array($row['record_type'], ['ordinary', 'invoice'], true)) {
            return $result;
        }
        $candidates = $this->candidates[$row['record_type']][$row['order_number']] ?? [];
        if ($candidates === []) {
            return $result;
        }
        $name = AnalysisWorkbookParser::key($row['customer_name'] ?? '');
        $orderDate = $row['customer_order_date'] ?? null;
        $matched = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool =>
            ($name === '' || $candidate['name'] === $name) && (!$orderDate || $candidate['date'] === $orderDate),
        ));
        if (count($matched) !== 1) {
            $result['order_match_status'] = $matched === [] ? 'conflict' : 'ambiguous';

            return $result;
        }
        // 没有来源网站时，至少要求姓名或顾客下单日期之一提供独立佐证。
        if ($name === '' && !$orderDate) {
            $result['order_match_status'] = 'needs_evidence';

            return $result;
        }
        $order = $matched[0];

        return [
            'linked_order_type' => $order['type'],
            'linked_order_id' => $order['id'],
            'order_match_status' => 'matched',
            'country' => $order['country'],
            'customer_type' => $this->customerTypes[$order['key']] ?? 'unknown',
        ];
    }

    /**
     * 在所有可用有效历史中判断首购；只有日期而无时分的同日多单保留未知顺序。
     *
     * @param array<string, array<int, array>> $history 按规范化邮箱分组的有效购买记录
     * @return void 设置订单键到 first、returning 或 unknown 的映射
     */
    private function buildCustomerTypes(array $history): void
    {
        foreach ($history as $orders) {
            usort($orders, static fn (array $left, array $right): int => strcmp($left['timestamp'], $right['timestamp']));
            $firstDate = $orders[0]['date'];
            $firstDay = array_values(array_filter($orders, static fn (array $order): bool => $order['date'] === $firstDate));
            $uncertain = count($firstDay) > 1 && count(array_filter($firstDay, static fn (array $order): bool => !$order['precise_time'])) > 0;
            $firstTime = $orders[0]['timestamp'];
            $ties = count(array_filter($firstDay, static fn (array $order): bool => $order['timestamp'] === $firstTime));
            foreach ($orders as $order) {
                $type = 'returning';
                if ($order['date'] === $firstDate && $uncertain) {
                    $type = 'unknown';
                } elseif ($order['timestamp'] === $firstTime) {
                    $type = $ties === 1 ? 'first' : 'unknown';
                }
                $this->customerTypes[$order['key']] = $type;
            }
        }
    }

    /**
     * 校验并规范化顾客邮箱；姓名和收款 PayPal 不参与顾客身份合并。
     *
     * @param mixed $value 来源邮箱
     * @return string|null 有效的规范化邮箱，格式无效时为空
     */
    private function email(mixed $value): ?string
    {
        $email = mb_strtolower(trim((string) $value));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * 统一常见国家别名；未知国家保持 null，不根据姓名或供应商推断国家。
     *
     * @param mixed $value 原始收货国家代码或名称
     * @return string|null 国家代码或原始国家名称；Unknown 等占位值为空
     */
    private function country(mixed $value): ?string
    {
        $country = trim((string) $value);
        $key = AnalysisWorkbookParser::key($country);
        if (in_array($key, ['', 'unknown', 'null', 'n/a', '未知'], true)) {
            return null;
        }
        $aliases = ['usa' => 'US', 'unitedstates' => 'US', 'unitedstatesofamerica' => 'US', 'uk' => 'GB', 'unitedkingdom' => 'GB', 'canada' => 'CA', 'australia' => 'AU', 'china' => 'CN'];

        return $aliases[$key] ?? (preg_match('/^[a-z]{2}$/i', $country) ? strtoupper($country) : $country);
    }
}
