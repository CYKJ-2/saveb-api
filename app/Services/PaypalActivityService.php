<?php

namespace App\Services;

use App\Dao\PaypalActivityDao;

/** 原 PayPal 收款接口口径；不套用销售绩效筛选，也不重复拼入 Invoice 表。 */
class PaypalActivityService
{
    /**
     * 注入 PayPal 收款处理所需的依赖。
     *
     * @param  PaypalActivityDao  $paypalActivityDao  PayPal 收款数据访问对象
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private PaypalActivityDao $paypalActivityDao)
    {
    }

    /**
     * 合并已入库订单和后续日快照，去重后按收款账户累计美元金额。
     *
     * @param  array<int, string>|null  $emails  仅计算指定收款邮箱；null 表示全部账户
     * @return array 以账户邮箱为键的 amount（美元收款合计）和 orders（订单明细）
     * @see PaypalActivityDao::orders()
     * @see PaypalActivityDao::rates()
     * @see PaypalActivityDao::daysAfter()
     */
    public function groups(?array $emails = null): array
    {
        if ($emails === []) {
            return [];
        }
        $emails = $emails === null ? null : array_values(array_unique(array_map(fn ($email) => strtolower(trim($email)), $emails)));
        $selectedEmails = $emails === null ? null : array_fill_keys($emails, true);
        $groups = [];
        $seen = [];
        $through = $emails === null ? '1970-01-01' : $this->paypalActivityDao->throughDate();
        foreach ($this->paypalActivityDao->orders($emails) as $order) {
            $date = $order->order_time?->utc()->toDateString() ?? '';
            $through = max($through, $date);
            $row = [
                'id' => $order->id,
                'orderId' => $order->client_order_id ?: $order->order_id,
                'clientOrderId' => $order->client_order_id ?: ($order->raw['clientOrderId'] ?? $order->order_id),
                'paypalOrderId' => $order->paypal_order_id ?: ($order->raw['orderId'] ?? ''),
                'customerFullName' => $order->customer_name,
                'clientSite' => $order->source_site,
                'classification' => $order->classification,
                'paymentStatus' => $order->order_status,
                'recipientPaypal' => strtolower(trim($order->receiving_paypal)),
                'amountUsd' => round((float) $order->amount_usd, 2),
                'amount' => (float) $order->amount_original,
                'currency' => $order->currency,
                'createTime' => $order->raw['createTime'] ?? $date,
                'date' => $date,
            ];
            $key = $this->key(
                $order->client_order_id,
                $order->paypal_order_id ?: $order->order_id,
                array_merge($row, ['createTime' => $order->order_time?->toIso8601String() ?? '']),
            );
            if ($selectedEmails !== null && isset($seen[$key])) {
                // 遗留重复键的取值依赖原全量查询顺序；不能因 SQL 筛选计划改变收款金额。
                // 仅这些账户回退到原查询，普通账户继续只读取自己的订单。
                return array_intersect_key($this->groups(), $selectedEmails);
            }
            $this->add($groups, $seen, $key, $row);
        }
        $rates = $this->paypalActivityDao->rates();
        foreach ($this->paypalActivityDao->daysAfter($through) as $day) {
            foreach ($day->payload['orders'] ?? $day->payload['recent'] ?? [] as $index => $order) {
                if ($selectedEmails !== null && !isset($selectedEmails[strtolower(trim($order['recipientPaypal'] ?? ''))])) {
                    continue;
                }
                $status = strtolower(trim($order['paymentStatus'] ?? $order['orderStatus'] ?? $order['status'] ?? ''));
                if (!in_array($status, ['completed', 'complete', 'paid', 'payment completed', 'payment_completed'], true)) {
                    continue;
                }
                $rate = (float) ($rates[strtoupper($order['currency'] ?? 'USD')] ?? 0);
                $row = [
                    'id' => 'legacy:' . $day->day->toDateString() . ':' . $index,
                    'orderId' => $order['clientOrderId'] ?? $order['orderId'] ?? '',
                    'clientOrderId' => $order['clientOrderId'] ?? '',
                    'paypalOrderId' => $order['orderId'] ?? $order['paypalOrderId'] ?? '',
                    'customerFullName' => $order['customerFullName'] ?? '',
                    'clientSite' => $order['clientSite'] ?? '',
                    'classification' => $order['category'] ?? $order['sourceCategory'] ?? '',
                    'paymentStatus' => $order['paymentStatus'] ?? 'Completed',
                    'recipientPaypal' => strtolower(trim($order['recipientPaypal'] ?? '')),
                    'amountUsd' => $rate ? round((float) ($order['amount'] ?? 0) / $rate, 2) : 0,
                    'amount' => (float) ($order['amount'] ?? 0),
                    'currency' => $order['currency'] ?? 'USD',
                    'createTime' => $order['createTime'] ?? '',
                    'date' => $day->day->toDateString(),
                ];
                $this->add($groups, $seen, $this->key($order['clientOrderId'] ?? '', $row['paypalOrderId'], $row), $row);
            }
        }
        foreach ($groups as &$group) {
            $group['amount'] = round($group['amount'], 2);
            usort($group['orders'], fn ($left, $right) => strcmp($right['createTime'], $left['createTime']) ?: strcmp((string) $right['id'], (string) $left['id']));
        }
        unset($group);

        return $groups;
    }

    /**
     * 组合订单标识、下单时间和收款账户，生成收款去重键。
     *
     * @param  string|null  $client  客户端订单编号，缺失时使用备用标识
     * @param  string|null  $order  备用来源订单编号
     * @param  array  $row  PayPal 收款单条记录
     * @return string 包含订单标识、时间及账户的稳定去重键
     */
    private function key(?string $client, ?string $order, array $row): string
    {
        $identity = $client ? 'client:' . trim($client) : ($order ? 'order:' . trim($order) : 'fallback:');

        return $identity . '|' . $row['createTime'] . '|' . $row['recipientPaypal'];
    }

    /**
     * 跳过无收款账户或已累计的订单，再写入账户金额和明细。
     *
     * @param  array  $groups  按统计维度索引的累计数据；按引用原地更新
     * @param  array  $seen  已累计订单的去重键集合；按引用原地更新
     * @param  string  $key  分组键或状态存储键
     * @param  array  $row  PayPal 收款单条记录
     * @return void 无返回值；副作用见方法说明
     */
    private function add(array &$groups, array &$seen, string $key, array $row): void
    {
        $email = $row['recipientPaypal'];
        if (!$email || isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $groups[$email] ??= ['amount' => 0, 'orders' => []];
        $groups[$email]['amount'] += $row['amountUsd'];
        $groups[$email]['orders'][] = $row;
    }
}
