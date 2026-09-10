<?php

namespace App\Services;

use App\Dao\SaSalesDao;

/**
 * SA 销售绩效服务：处理业务规则、统计口径和事务。
 */
class SaSalesService
{
    /**
     * 注入 SA 销售处理所需的依赖。
     *
     * @param  SaSalesDao  $saSalesDao  SA 销售数据访问对象
     * @param  OrderManagementService  $orderManagementService  订单管理业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private SaSalesDao $saSalesDao, private OrderManagementService $orderManagementService)
    {
    }

    /**
     * 读取可用业务日期范围。
     *
     * @return array 数据刷新时间 refreshedAt、最早日期 firstDate 和覆盖日期 dataThrough
     * @see SaSalesDao::bounds()
     */
    public function bounds(): array
    {
        return $this->saSalesDao->bounds();
    }

    /**
     * 返回独立明细表单所需的销售员选项。
     *
     * @return array SA 销售结果数组；返回字段：employees
     * @see SaSalesDao::staffCodes()
     */
    public function orderOptions(): array
    {
        return ['employees' => $this->saSalesDao->staffCodes()];
    }

    /**
     * 明细按独立条件查询和分页，不受绩效报表仅统计已完成及退款订单的限制。
     * 未选分类时排除 Invoice；指定 Invoice 时沿用订单管理的合并去重规则。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件；本方法读取 customerService、classification
     * @return array 当前页记录及分页信息；汇总字段按业务方法计算
     * @see OrderManagementService::rows()
     */
    public function orders(array $filters): array
    {
        $unassigned = ($filters['customerService'] ?? '') === 'Unassigned';
        if ($unassigned) {
            unset($filters['customerService']);
        }
        $rows = $this->orderManagementService->rows($filters + ['staffExact' => true]);
        $rows = array_values(array_filter(
            $rows,
            fn ($row) =>
            (!empty($filters['classification']) || $row['classification'] !== 'invoice')
            && (!$unassigned || !$row['staffAllocations']),
        ));
        $totalAmount = 0;
        foreach ($rows as $row) {
            $totalAmount += $this->isRefund($row) ? -abs($row['amountUsd'] ?? 0) : ($row['amountUsd'] ?? 0);
        }
        $page = \App\Common\PageResult::fromRows($rows, $filters);
        $pageRows = $this->enrichSalesSources($page['list']);
        $details = [];
        foreach ($pageRows as $row) {
            $refund = $this->isRefund($row);
            $amount = $row['amountUsd'];
            $details[] = $this->presentDetail($row, $refund && $amount !== null ? -abs($amount) : $amount, $refund);
        }

        $page['list'] = $details;

        return $page + ['totalAmount' => round($totalAmount, 2)];
    }

    /**
     * 退款状态或负金额均按退款展示，保持明细与统计的金额符号一致。
     *
     * @param  array  $row  SA 销售单条记录
     * @return bool 状态属于退款或美元金额为负数时为 true
     */
    private function isRefund(array $row): bool
    {
        return in_array($row['paymentStatus'], ['refunded', 'reversed', 'chargeback', 'returned'], true)
            || ($row['amountUsd'] ?? 0) < 0;
    }

    /**
     * 补充来源渠道与收款信息；只读取当前所需订单的轻量来源字段。
     *
     * @param  array  $rows  SA 销售记录列表
     * @return array 补充 salesChannel、paymentMethod、paymentAccount 的订单列表
     * @see SaSalesDao::reportSources()
     */
    private function enrichSalesSources(array $rows): array
    {
        $orderIds = array_column(array_filter($rows, fn ($row) => $row['kind'] === 'order'), 'id');
        $sources = $this->saSalesDao->reportSources($orderIds);
        foreach ($rows as $index => $row) {
            $source = $row['kind'] === 'order' ? ($sources[$row['id']] ?? []) : [];
            $row['salesChannel'] = $source['channel'] ?? $row['clientSite'] ?? $row['classification'];
            $row['paymentMethod'] = $source['paymentMethod'] ?? (empty($row['recipientPaypal']) && empty($row['paypalOrderId']) ? 'Unknown' : 'PayPal');
            $row['paymentAccount'] = empty($row['recipientPaypal']) ? 'Unknown' : strtolower(trim($row['recipientPaypal']));
            $rows[$index] = $row;
        }

        return $rows;
    }

    /**
     * 按销售额阶梯计算佣金。
     *
     * @param  float  $sales  用于阶梯佣金计算的美元销售额
     * @return float 按阶梯计算的美元提成金额，保留两位小数
     */
    public static function commission(float $sales): float
    {
        $remaining = max(0, $sales);
        $amount = 0;
        foreach ([[40000, 0.015], [20000, 0.02], [20000, 0.025], [INF, 0.03]] as [$size, $rate]) {
            $slice = min($remaining, $size);
            $amount += $slice * $rate;
            $remaining -= $slice;
            if ($remaining <= 0) {
                break;
            }
        }

        return round($amount, 2);
    }

    /**
     * 汇总客服、渠道、日期和收款账户绩效。
     *
     * @param  array  $rows  SA 销售记录列表
     * @param  bool  $includeDetails  是否同时生成订单明细；默认 true
     * @return array SA 销售结果数组；返回字段：metrics、employees、channels、daily、paymentMethods、paymentAccounts、breakdowns、detail
     */
    private function summarize(array $rows, bool $includeDetails = true): array
    {
        $employees = [];
        $channels = [];
        $daily = [];
        $payment = [];
        $accounts = [];
        $detail = [];
        $total = [
            'orders' => 0,
            'refundOrders' => 0,
            'positiveSales' => 0,
            'refundAmount' => 0,
            'netSales' => 0,
            'totalCommission' => 0,
            'activeDays' => 0,
            'dailyAverage' => 0,
            'averageOrderValue' => 0,
            'topSeller' => '—',
            'missingRates' => 0,
        ];
        foreach ($rows as $row) {
            $refund = $this->isRefund($row);
            if (!$refund && $row['paymentStatus'] !== 'completed') {
                continue;
            }
            if ($row['amountUsd'] === null) {
                $total['missingRates']++;
                continue;
            }
            $amount = $refund ? -abs($row['amountUsd']) : $row['amountUsd'];
            $total[$refund ? 'refundOrders' : 'orders']++;
            $total['netSales'] += $amount;
            $total[$refund ? 'refundAmount' : 'positiveSales'] += abs($amount);
            $date = $row['date'];
            $daily[$date] ??= [
                'date' => $date,
                'orders' => 0,
                'refundOrders' => 0,
                'sales' => 0,
                'refunds' => 0,
                'netSales' => 0,
            ];
            $daily[$date][$refund ? 'refundOrders' : 'orders']++;
            $daily[$date]['netSales'] += $amount;
            $daily[$date][$refund ? 'refunds' : 'sales'] += abs($amount);
            $this->accumulateDimension($channels, $row['salesChannel'], $amount, $refund);
            $this->accumulateDimension($payment, $row['paymentMethod'], $amount, $refund);
            $this->accumulateDimension($accounts, $row['paymentAccount'], $amount, $refund);
            $this->accumulateEmployees($employees, $row, $amount, $refund);
            if ($includeDetails) {
                $detail[] = $this->presentDetail($row, $amount, $refund);
            }
        }
        $employees = $this->finalizeEmployees($employees);
        foreach ($employees as $employee) {
            $total['totalCommission'] += $employee['commission'];
        }
        $total = $this->finalizeMetrics($total, $daily, $employees);
        ksort($daily);
        $channels = $this->finalizeDimension($channels, $total['positiveSales']);
        $payment = $this->finalizeDimension($payment, $total['positiveSales']);
        $accounts = $this->finalizeDimension($accounts, $total['positiveSales']);

        return [
            'metrics' => $total,
            'employees' => array_values($employees),
            'channels' => $channels,
            'daily' => $this->roundGroups($daily),
            'paymentMethods' => $payment,
            'paymentAccounts' => $accounts,
            'breakdowns' => [
                'channel' => $channels,
                'employee' => array_values($employees),
                'paymentMethod' => $payment,
                'paymentAccount' => $accounts,
            ],
            'detail' => $detail,
        ];
    }

    /**
     * 生成绩效报表。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件；本方法读取 includeDetails
     * @return array 员工排行、渠道统计、每日趋势、支付分布和总体销售指标
     * @see OrderManagementService::rows()
     */
    public function report(array $filters): array
    {
        $includeDetails = (bool) ($filters['includeDetails'] ?? true);
        unset($filters['includeDetails']);
        $rows = $this->enrichSalesSources($this->orderManagementService->rows($filters + ['_withProducts' => $includeDetails]));
        $regular = [];
        $invoices = [];
        foreach ($rows as $row) {
            if ($row['classification'] === 'invoice') {
                $invoices[] = $row;
            } else {
                $regular[] = $row;
            }
        }

        return $this->summarize($regular, $includeDetails) + [
            'invoiceSales' => $this->summarize($invoices, $includeDetails),
            'source' => $this->bounds(),
            'range' => $filters,
        ];
    }

    /**
     * 生成导出记录。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件
     * @return iterable 按需迭代的SA 销售记录，供逐条处理或导出
     */
    public function exportRows(array $filters): iterable
    {
        $report = $this->report($filters);
        $labels = [
            'orders' => '订单数',
            'refundOrders' => '退款订单',
            'positiveSales' => '销售额',
            'refundAmount' => '退款额',
            'netSales' => '净销售',
            'totalCommission' => '佣金',
            'activeDays' => '活跃天数',
            'dailyAverage' => '日均销售',
            'averageOrderValue' => '平均订单金额',
            'topSeller' => '最佳销售',
            'missingRates' => '缺少汇率',
        ];
        foreach ([
            'SA 销售' => $report,
            'Invoice 销售' => $report['invoiceSales'],
        ] as $scope => $data) {
            foreach ($data['metrics'] as $key => $value) {
                yield [
                    'section' => $scope . ' / 总览',
                    'name' => $labels[$key] ?? $key,
                    'value' => $value,
                ];
            }
            foreach ([
                'employees' => '客服排名',
                'channels' => '渠道',
                'daily' => '每日汇总',
                'paymentMethods' => '支付方式',
                'paymentAccounts' => '收款账户',
            ] as $key => $title) {
                foreach ($data[$key] as $row) {
                    yield ['section' => $scope . ' / ' . $title] + $row;
                }
            }
            foreach ($data['detail'] as $row) {
                yield ['section' => $scope . ' / 订单明细'] + $row;
            }
        }
    }

    /**
     * 按维度累计订单和退款；退款减少净销售额，单独统计退款单数。
     *
     * @param  array  $groups  按统计维度索引的累计数据；按引用原地更新
     * @param  string  $name  当前业务对象的名称
     * @param  float  $amount  当前计算或登记的金额
     * @param  bool  $refund  当前订单是否按退款口径计算
     * @return void 无返回值；副作用见方法说明
     */
    private function accumulateDimension(
        array &$groups,
        string $name,
        float $amount,
        bool $refund,
    ): void {
        $groups[$name] ??= [
            'name' => $name,
            'orders' => 0,
            'refundOrders' => 0,
            'sales' => 0,
            'refunds' => 0,
            'netSales' => 0,
        ];
        $groups[$name][$refund ? 'refundOrders' : 'orders']++;
        $groups[$name]['netSales'] += $amount;
        $groups[$name][$refund ? 'refunds' : 'sales'] += abs($amount);
    }

    /**
     * 客服金额按比例分摊，订单数按参与客服各记一单，日期用于活跃天数。
     *
     * @param  array  $employees  按员工编码组织的销售累计数据；按引用原地更新
     * @param  array  $row  SA 销售单条记录
     * @param  float  $amount  当前计算或登记的金额
     * @param  bool  $refund  当前订单是否按退款口径计算
     * @return void 无返回值；副作用见方法说明
     */
    private function accumulateEmployees(
        array &$employees,
        array $row,
        float $amount,
        bool $refund,
    ): void {
        $date = $row['date'];
        foreach ($row['staffAllocations'] ?: [[
            'staffCode' => 'Unassigned',
            'shareRatio' => 1,
        ]] as $share) {
            $name = $share['staffCode'];
            $employees[$name] ??= [
                'name' => $name,
                'orders' => 0,
                'refundOrders' => 0,
                'sales' => 0,
                'refunds' => 0,
                'netSales' => 0,
                'dates' => [],
            ];
            $employees[$name][$refund ? 'refundOrders' : 'orders']++;
            $employees[$name]['netSales'] += $amount * $share['shareRatio'];
            $employees[$name][$refund ? 'refunds' : 'sales'] += abs($amount) * $share['shareRatio'];
            $employees[$name]['dates'][$date] = true;
        }
    }

    /**
     * 生成带退款标记的订单明细，供报表和 CSV 导出共用。
     *
     * @param  array  $row  SA 销售单条记录
     * @param  float|null  $amount  当前计算或登记的金额
     * @param  bool  $refund  当前订单是否按退款口径计算
     * @return array SA 销售结果数组；返回字段：id、identity、date、orderId、customer、amountUsd、website、classification、staff、channel、paymentMethod、account、status、staffAllocations、refund
     */
    private function presentDetail(
        array $row,
        ?float $amount,
        bool $refund,
    ): array {
        $date = $row['date'];

        return [
            'id' => $row['kind'] . ':' . $row['id'],
            'identity' => $row['sourceIdentity'] ?? 'invoice:' . $row['orderId'],
            'date' => $date,
            'orderId' => $row['orderId'],
            'customer' => $row['customerFullName'],
            'amountUsd' => $amount === null ? null : round($amount, 2),
            'website' => $row['clientSite'],
            'classification' => $row['classification'],
            'staff' => implode(', ', array_column($row['staffAllocations'], 'staffCode')),
            'channel' => $row['salesChannel'],
            'paymentMethod' => $row['paymentMethod'],
            'account' => $row['paymentAccount'],
            'status' => $row['paymentStatus'],
            'staffAllocations' => $row['staffAllocations'],
            'refund' => $refund,
        ];
    }

    /**
     * 按净销售额排名并结算阶梯佣金；未分配客服不计佣金。
     *
     * @param  array  $employees  按员工编码组织的销售累计数据
     * @return array 按净销售额降序排列、已计算提成和活跃天数的员工列表
     */
    private function finalizeEmployees(array $employees): array
    {
        uasort($employees, fn ($firstRow, $secondRow) => $secondRow['netSales'] <=> $firstRow['netSales']);
        $maximumSales = max([0, ...array_column($employees, 'netSales')]);
        $rank = 0;
        foreach ($employees as $staffCode => $employee) {
            $employee['rank'] = ++$rank;
            $employee['sales'] = round($employee['sales'], 2);
            $employee['refunds'] = round($employee['refunds'], 2);
            $employee['netSales'] = round($employee['netSales'], 2);
            $employee['activeDays'] = count($employee['dates']);
            unset($employee['dates']);
            $employee['commission'] = $employee['name'] === 'Unassigned' ? 0 : self::commission($employee['netSales']);
            $employee['dailyAverage'] = $employee['activeDays'] ? round($employee['netSales'] / $employee['activeDays'], 2) : 0;
            $employee['relativePercent'] = $maximumSales > 0 ? round(max(0, $employee['netSales']) / $maximumSales * 100, 2) : 0;
            $employees[$staffCode] = $employee;
        }

        return $employees;
    }

    /**
     * 汇总活跃天数、客单价及最佳销售，最后统一舍入金额。
     *
     * @param  array  $total  当前范围的汇总指标
     * @param  array  $daily  按业务日期组织的每日统计
     * @param  array  $employees  按员工编码组织的销售累计数据
     * @return array 包含活跃天数、日均、客单价和最佳销售的最终汇总指标
     */
    private function finalizeMetrics(
        array $total,
        array $daily,
        array $employees,
    ): array {
        $total['activeDays'] = count($daily);
        $total['dailyAverage'] = $daily ? round($total['netSales'] / count($daily), 2) : 0;
        $total['averageOrderValue'] = $total['orders'] ? round($total['positiveSales'] / $total['orders'], 2) : 0;
        foreach ($employees as $employee) {
            if ($employee['name'] !== 'Unassigned' && $employee['netSales'] > 0) {
                $total['topSeller'] = $employee['name'];
                break;
            }
        }
        foreach (['positiveSales', 'refundAmount', 'netSales', 'totalCommission'] as $field) {
            $total[$field] = round($total[$field], 2);
        }

        return $total;
    }

    /**
     * 将各维度净销售额保留两位小数，避免跨循环共享数组引用。
     *
     * @param  array  $groups  按统计维度索引的累计数据
     * @return array 净销售额保留两位小数后的分组数据
     */
    private function roundGroups(array $groups): array
    {
        foreach ($groups as $key => $group) {
            foreach (['sales', 'refunds', 'netSales'] as $field) {
                $group[$field] = round($group[$field], 2);
            }
            $groups[$key] = $group;
        }

        return array_values($groups);
    }

    /**
     * 渠道和销售占比沿用 source 的正销售额口径，退款不改变销售份额分母。
     *
     * @param  array  $groups  按统计维度索引的累计数据
     * @param  float  $positiveSales  正销售额合计，用作销售占比分母
     * @return array 已计算客单价和正销售额占比的维度统计列表
     */
    private function finalizeDimension(array $groups, float $positiveSales): array
    {
        $groups = $this->roundGroups($groups);
        foreach ($groups as $index => $group) {
            $group['sharePercent'] = $positiveSales > 0 ? round($group['sales'] / $positiveSales * 100, 2) : 0;
            $groups[$index] = $group;
        }
        usort($groups, fn ($first, $second) => $second['netSales'] <=> $first['netSales'] ?: strcmp($first['name'], $second['name']));

        return $groups;
    }
}
