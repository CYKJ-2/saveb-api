<?php

namespace App\Services;

use App\Dao\DashboardOverviewDao;
use Carbon\CarbonImmutable;

/**
 * 首页概览服务：处理业务规则、统计口径和事务。
 */
class DashboardOverviewService
{
    public const MODULES = [
        'overview',
        'sales-trend',
        'categories',
        'influencers',
        'staff',
        'recent-orders',
        'currencies',
        'paypal',
        'exchange-rates',
        'system-status',
        'spreadsheets',
    ];

    /**
     * 注入 首页概览处理所需的依赖。
     *
     * @param  DashboardOverviewDao  $dashboardOverviewDao  首页概览数据访问对象
     * @param  OrderManagementService  $orderManagementService  订单管理业务服务
     * @param  OrderStatisticsService  $orderStatisticsService  订单统计业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(
        private DashboardOverviewDao $dashboardOverviewDao,
        private OrderManagementService $orderManagementService,
        private OrderStatisticsService $orderStatisticsService,
    ) {
    }

    /**
     * 读取首页概览详情或指定模块数据。
     *
     * @param  string  $module  要查询的统计或业务模块标识
     * @param  array  $range  统计日期范围，包含 startDate、endDate，格式 Y-m-d；本方法读取 startDate、endDate
     * @param  string  $granularity  趋势统计粒度，day 按日、month 按月；默认 'day'
     * @return array{range: array, timezone: string, generatedAt: string, data: array} 实际统计范围、时区、生成时间与当前模块数据
     * @see DashboardOverviewDao::exchangeRates()
     * @see DashboardOverviewDao::dataStatus()
     * @see DashboardOverviewDao::spreadsheets()
     * @see OrderStatisticsService::statistics()
     */
    public function show(
        string $module,
        array $range,
        string $granularity = 'day',
    ): array {
        // 首页月趋势统计筛选涉及的完整自然年；其他模块仍使用原筛选日期。
        // Controller 已先校验原始区间，跨年时可展示两个年份的完整月份。
        if ($module === 'sales-trend' && $granularity === 'month') {
            $range = [
                'startDate' => CarbonImmutable::parse($range['startDate'])->startOfYear()->toDateString(),
                'endDate' => CarbonImmutable::parse($range['endDate'])->endOfYear()->toDateString(),
            ];
        }
        $data = match ($module) {
            'overview' => $this->overview($range),
            'recent-orders' => $this->recent($range),
            'paypal' => $this->paypal($range),
            'exchange-rates' => [
                'list' => $this->dashboardOverviewDao->exchangeRates($range['endDate']),
                'asOf' => $range['endDate'],
            ],
            'system-status' => $this->dashboardOverviewDao->dataStatus(),
            'spreadsheets' => ['list' => $this->dashboardOverviewDao->spreadsheets()],
            default => $this->orderStatisticsService->statistics($module, $range + ['granularity' => $granularity]),
        };

        return [
            'range' => $range,
            'timezone' => 'Asia/Shanghai',
            'generatedAt' => now('Asia/Shanghai')->toIso8601String(),
            'data' => $data,
        ];
    }

    /**
     * 汇总完成订单、件数和美元金额。
     *
     * @param  array  $rows  首页概览记录列表
     * @return array 完成订单数、商品件数、美元销售额和缺失汇率计数
     */
    private function totals(array $rows): array
    {
        $data = [
            'orders' => 0,
            'items' => 0,
            'amountUsd' => 0,
            'missingRates' => 0,
            'averageOrderValue' => 0,
        ];
        $priced = 0;
        foreach ($rows as $row) {
            if ($row['classification'] === 'invoice' || $row['paymentStatus'] !== 'completed') {
                continue;
            }
            $data['orders']++;
            $data['items'] += $row['items'];
            if ($row['amountUsd'] === null) {
                $data['missingRates']++;
            } else {
                $data['amountUsd'] += $row['amountUsd'];
                $priced++;
            }
        }
        $data['amountUsd'] = round($data['amountUsd'], 2);
        $data['averageOrderValue'] = $priced ? round($data['amountUsd'] / $priced, 2) : 0;

        return $data;
    }

    /**
     * 计算当前区间及上一等长区间指标。
     *
     * @param  array  $range  统计日期范围，包含 startDate、endDate，格式 Y-m-d；本方法读取 startDate、endDate
     * @return array 当前日期区间指标、上一等长区间指标及变化数据
     * @see OrderManagementService::rows()
     */
    private function overview(array $range): array
    {
        $start = CarbonImmutable::parse($range['startDate']);
        $end = CarbonImmutable::parse($range['endDate']);
        $days = (int) $start->diffInDays($end) + 1;
        $previousRange = [
            'startDate' => $start
                ->subDays($days)
                ->toDateString(),
            'endDate' => $start
                ->subDay()
                ->toDateString(),
        ];
        $rows = $this->orderManagementService->rows($range);
        $current = $this->totals($rows);
        $previous = $this->totals($this->orderManagementService->rows($previousRange));
        $changes = [];
        foreach (['orders', 'items', 'amountUsd', 'averageOrderValue'] as $key) {
            $changes[$key] = $previous[$key] != 0 ? round(($current[$key] - $previous[$key]) / abs($previous[$key]) * 100, 2) : null;
        }
        $statuses = [];
        foreach ($rows as $row) {
            $key = $row['paymentStatus'] ?: 'unknown';
            $statuses[$key] = ($statuses[$key] ?? 0) + 1;
        }

        return compact('current', 'previous', 'previousRange', 'changes', 'statuses') + ['allOrders' => count($rows)];
    }

    /**
     * 提取最近订单并限制输出字段。
     *
     * @param  array  $range  统计日期范围，包含 startDate、endDate，格式 Y-m-d
     * @return array 首页概览结果数组；返回字段：list、total
     * @see OrderManagementService::rows()
     */
    private function recent(array $range): array
    {
        $rows = $this->orderManagementService->rows($range);
        $fields = array_flip([
            'id',
            'kind',
            'orderId',
            'customerFullName',
            'clientSite',
            'classification',
            'staff',
            'amountUsd',
            'currency',
            'items',
            'createTime',
            'paymentStatus',
        ]);

        return [
            'list' => array_map(fn ($row) => array_intersect_key($row, $fields), array_slice($rows, 0, 10)),
            'total' => count($rows),
        ];
    }

    /**
     * 汇总查询期间内的收款和提现。
     *
     * @param  array  $range  统计日期范围，包含 startDate、endDate，格式 Y-m-d
     * @return array 首页概览结果数组；返回字段：list、activeAccounts、periodAccounts、received、withdrawn
     * @see OrderManagementService::rows()
     * @see DashboardOverviewDao::withdrawals()
     * @see DashboardOverviewDao::paypalAccounts()
     */
    private function paypal(array $range): array
    {
        $received = [];
        foreach ($this->orderManagementService->rows($range + ['orderStatus' => 'completed']) as $row) {
            $email = strtolower(trim($row['recipientPaypal'] ?? ''));
            if (!$email) {
                continue;
            }
            $received[$email] ??= [
                'email' => $email,
                'accountName' => '未登记账户',
                'orders' => 0,
                'received' => 0,
                'withdrawn' => 0,
            ];
            $received[$email]['orders']++;
            $received[$email]['received'] += $row['amountUsd'] ?? 0;
        }
        $withdrawals = $this->dashboardOverviewDao->withdrawals($range);
        $accounts = $this->dashboardOverviewDao->paypalAccounts();
        foreach ($accounts as $account) {
            $email = strtolower(trim($account['email']));
            if (!isset($received[$email]) && !isset($withdrawals[$account['id']])) {
                continue;
            }
            $received[$email] ??= [
                'email' => $email,
                'orders' => 0,
                'received' => 0,
                'withdrawn' => 0,
            ];
            $received[$email]['accountName'] = $account['account_name'];
            $received[$email]['withdrawn'] = round((float) ($withdrawals[$account['id']] ?? 0), 2);
        }
        foreach ($received as &$row) {
            $row['received'] = round($row['received'], 2);
        }
        unset($row);
        uasort($received, fn ($firstRow, $secondRow) => $secondRow['received'] <=> $firstRow['received']);

        return [
            'list' => array_slice(array_values($received), 0, 10),
            'activeAccounts' => count(array_filter($accounts, fn ($account) => $account['active'] && !$account['deleted_at'])),
            'periodAccounts' => count($received),
            'received' => round(array_sum(array_column($received, 'received')), 2),
            'withdrawn' => round(array_sum($withdrawals), 2),
        ];
    }
}
