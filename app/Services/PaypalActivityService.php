<?php

namespace App\Services;

use App\Dao\PaypalActivityDao;

/** 原 PayPal 收款接口口径；不套用销售绩效筛选，也不重复拼入 Invoice 表。 */
class PaypalActivityService
{
    public function __construct(private PaypalActivityDao $paypalActivityDao)
    {
    }

    public function groups(): array
    {
        $groups = [];
        $seen = [];
        $through = '1970-01-01';
        foreach ($this->paypalActivityDao->orders() as $order) {
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
            $this->add($groups, $seen, $key, $row);
        }
        $rates = $this->paypalActivityDao->rates();
        foreach ($this->paypalActivityDao->daysAfter($through) as $day) {
            foreach ($day->payload['orders'] ?? $day->payload['recent'] ?? [] as $index => $order) {
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
            usort($group['orders'], fn ($left, $right) => strcmp($right['createTime'], $left['createTime']));
        }
        unset($group);

        return $groups;
    }

    private function key(?string $client, ?string $order, array $row): string
    {
        $identity = $client ? 'client:' . trim($client) : ($order ? 'order:' . trim($order) : 'fallback:');

        return $identity . '|' . $row['createTime'] . '|' . $row['recipientPaypal'];
    }

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
