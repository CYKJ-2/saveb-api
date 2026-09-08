<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * 订单统计服务：处理业务规则、统计口径和事务。
 */
class OrderStatisticsService
{
    public function __construct(private OrderManagementService $orderManagementService)
    {
    }

    /**
     * 按统计模块汇总数据。
     */
    public function statistics(string $module, array $filters): array
    {
        $filters['orderStatus'] = 'completed';
        $filters['scope'] = 'normal';
        $rows = $this->orderManagementService->rows($filters);
        $total = [
            'orders' => 0,
            'items' => 0,
            'amountUsd' => 0,
            'missingRates' => 0,
        ];
        $groups = [];
        foreach ($rows as $row) {
            // 原首页总览和币种面板不含 Invoice，分类与趋势统计仍包含 Invoice。
            if (in_array($module, ['overview', 'currencies'], true) && $row['classification'] === 'invoice') {
                continue;
            }
            // 总额先于客服、达人分组过滤累计，保持原页面的占比分母。
            $total['orders']++;
            $total['items'] += $row['items'];
            $total['amountUsd'] += $row['amountUsd'] ?? 0;
            if ($row['amountUsd'] === null) {
                $total['missingRates']++;
            }
            $key = match ($module) {
                'currencies' => $row['currency'],
                'categories' => $row['classification'],
                'influencers' => $row['topInfluencer'] ?: '',
                'sales-trend' => substr($row['date'], 0, ($filters['granularity'] ?? 'day') === 'month' ? 7 : 10),
                default => '',
            };
            if ($module === 'staff') {
                if ($row['classification'] !== 'offline') {
                    continue;
                }
                $this->accumulateStaff($groups, $row);
                continue;
            }
            if ($module === 'influencers' && (!$key || !in_array($row['classification'], ['top_influencer', 'mid_influencer']))) {
                continue;
            }
            $this->accumulateGroup($groups, $key, $row);
        }
        $total['amountUsd'] = round($total['amountUsd'], 2);
        if ($module === 'overview') {
            return $total;
        }
        if ($module === 'sales-trend') {
            $groups = $this->fillTrendPeriods($groups, $filters);
        } else {
            uasort($groups, fn ($firstRow, $secondRow) => $secondRow['amountUsd'] <=> $firstRow['amountUsd']);
        }

        return [
            'list' => $this->presentGroups($groups, $total),
            'totals' => $total,
        ];
    }

    /**
     * 线下订单按客服分摊比例累计单数、件数和美元金额。
     */
    private function accumulateStaff(array &$groups, array $row): void
    {
        foreach ($row['staffAllocations'] as $allocation) {
            $code = $allocation['staffCode'];
            $ratio = $allocation['shareRatio'];
            $groups[$code] ??= [
                'key' => $code,
                'orders' => 0,
                'items' => 0,
                'amountUsd' => 0,
            ];
            $groups[$code]['orders'] += $ratio;
            $groups[$code]['items'] += $row['items'] * $ratio;
            $groups[$code]['amountUsd'] += ($row['amountUsd'] ?? 0) * $ratio;
        }
    }

    /**
     * 按统计维度累计金额，并保留分类序列供趋势图使用。
     */
    private function accumulateGroup(
        array &$groups,
        string|int|null $key,
        array $row,
    ): void {
        $groups[$key] ??= [
            'key' => $key,
            'orders' => 0,
            'items' => 0,
            'amountUsd' => 0,
            'amountOriginal' => 0,
            'series' => [],
            'orderSeries' => [],
        ];
        $groups[$key]['orders']++;
        $groups[$key]['items'] += $row['items'];
        $groups[$key]['amountUsd'] += $row['amountUsd'] ?? 0;
        $groups[$key]['amountOriginal'] += $row['amount'] ?? 0;
        $category = $row['classification'];
        $groups[$key]['series'][$category] = ($groups[$key]['series'][$category] ?? 0) + ($row['amountUsd'] ?? 0);
        $groups[$key]['orderSeries'][$category] = ($groups[$key]['orderSeries'][$category] ?? 0) + 1;
    }

    /**
     * 补齐无订单的日期或月份，让趋势图的时间轴连续。
     */
    private function fillTrendPeriods(array $groups, array $filters): array
    {
        $date = CarbonImmutable::parse($filters['startDate']);
        $end = CarbonImmutable::parse($filters['endDate']);
        $monthly = ($filters['granularity'] ?? 'day') === 'month';
        if ($monthly) {
            $date = $date->startOfMonth();
        }
        while ($date <= $end) {
            $key = $date->format($monthly ? 'Y-m' : 'Y-m-d');
            $groups[$key] ??= [
                'key' => $key,
                'orders' => 0,
                'items' => 0,
                'amountUsd' => 0,
                'series' => [],
                'orderSeries' => [],
            ];
            $date = $monthly ? $date->addMonth() : $date->addDay();
        }
        ksort($groups);

        return $groups;
    }

    /**
     * 汇总完成后统一舍入；占比始终使用当前模块的总销售额。
     */
    private function presentGroups(array $groups, array $total): array
    {
        foreach ($groups as &$group) {
            foreach (['amountUsd', 'amountOriginal', 'orders', 'items'] as $field) {
                if (isset($group[$field])) {
                    $group[$field] = round($group[$field], 2);
                }
            }
            // 分类柱状图直接使用 series，必须和分组总金额一起在累计完成后舍入。
            if (isset($group['series'])) {
                $group['series'] = array_map(fn ($amount) => round($amount, 2), $group['series']);
            }
            $group['share'] = $total['amountUsd'] ? round($group['amountUsd'] / $total['amountUsd'] * 100, 2) : 0;
        }

        return array_values($groups);
    }
}
