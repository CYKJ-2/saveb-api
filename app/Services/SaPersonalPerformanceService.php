<?php

namespace App\Services;

use App\Common\PageResult;
use App\Dao\OrderManagementDao;
use App\Dao\SaPersonalPerformanceDao;
use Carbon\CarbonImmutable;

/** 复用订单的覆盖、分摊、汇率及 Invoice 去重规则，统计指定客服的实际分摊业绩。 */
class SaPersonalPerformanceService
{
    /**
     * 注入统一订单口径和个人业绩附加字段访问器。
     *
     * @param OrderManagementService $orderManagementService 标准化订单及筛选服务
     * @param OrderManagementDao $orderManagementDao 历史及在职客服编码查询
     * @param SaPersonalPerformanceDao $personalPerformanceDao 名称与当前页联系信息查询
     * @return void 完成依赖初始化
     */
    public function __construct(
        private OrderManagementService $orderManagementService,
        private OrderManagementDao $orderManagementDao,
        private SaPersonalPerformanceDao $personalPerformanceDao,
    ) {
    }

    /**
     * 合并历史参与人和用户名称，默认选择当前客服，否则选择首个可用员工。
     *
     * @param string|null $currentStaffCode 登录用户绑定的客服编码
     * @return array employees 中每项包含 code/name；defaultStaffCode 无员工时为空字符串
     */
    public function options(?string $currentStaffCode): array
    {
        $names = [];
        foreach ($this->personalPerformanceDao->employeeNames() as $user) {
            $code = strtoupper(trim($user['staff_code'] ?? ''));
            if ($code !== '' && !isset($names[$code])) {
                $names[$code] = trim($user['display_name'] ?? '') ?: $user['username'];
            }
        }
        $codes = $this->orderManagementDao->staffCodes();
        $employees = array_map(fn ($code) => ['code' => $code, 'name' => $names[$code] ?? $code], $codes);
        $currentStaffCode = strtoupper(trim($currentStaffCode ?? ''));

        return [
            'employees' => $employees,
            'defaultStaffCode' => in_array($currentStaffCode, $codes, true) ? $currentStaffCode : ($codes[0] ?? ''),
        ];
    }

    /**
     * 一次读取所选日期的轻量订单，汇总和明细使用相同的客服精确匹配结果。
     *
     * @param array $filters 已校验的员工编码、日期、订单范围、分页及 includeSummary 参数
     * @return array range；summary（美元业绩汇总）；daily（含无订单日期）；orders（默认 20 条）
     */
    public function report(array $filters): array
    {
        $staffCode = strtoupper($filters['staffCode']);
        $scope = $filters['scope'] ?? 'all';
        $source = $this->orderManagementService->rows([
            'startDate' => $filters['startDate'], 'endDate' => $filters['endDate'],
            'customerService' => $staffCode, 'staffExact' => true, '_withProducts' => false,
        ]);
        $rows = [];
        foreach ($source as $row) {
            $kind = $row['classification'] === 'invoice' ? 'invoice' : 'order';
            $refund = $this->isRefund($row);
            if (($scope !== 'all' && $scope !== $kind) || (!$refund && $row['paymentStatus'] !== 'completed')) {
                continue;
            }
            $allocation = collect($row['staffAllocations'])->firstWhere('staffCode', $staffCode);
            if (!$allocation || $allocation['shareRatio'] <= 0) {
                continue;
            }
            $amount = $row['amountUsd'] === null ? null : abs((float) $row['amountUsd']) * ($refund ? -1 : 1);
            $rows[] = [
                'id' => $row['kind'] === 'invoice' ? $row['id'] : 'order:' . $row['id'], 'sourceId' => $row['id'], 'sourceKind' => $row['kind'],
                'kind' => $kind, 'date' => $row['date'], 'orderId' => $row['orderId'],
                'customer' => $row['customerFullName'], 'website' => $row['clientSite'],
                'classification' => $row['classification'], 'status' => $row['paymentStatus'],
                'account' => $row['recipientPaypal'] ?? '', 'refund' => $refund,
                'orderAmount' => $amount, 'sharePercent' => $allocation['shareRatio'] * 100,
                'myAmount' => $amount === null ? null : $amount * $allocation['shareRatio'],
            ];
        }
        $page = PageResult::fromRows($rows, $filters);
        $page['list'] = $this->presentPage($page['list']);
        $statistics = ($filters['includeSummary'] ?? true) ? $this->summarize($rows, $filters) : ['summary' => null, 'daily' => []];

        return $statistics + [
            'range' => ['staffCode' => $staffCode, 'startDate' => $filters['startDate'], 'endDate' => $filters['endDate'], 'scope' => $scope],
            'orders' => $page,
        ];
    }

    /**
     * 与 SA 报表一致，将退款状态或负金额识别为退款。
     *
     * @param array $row 标准化订单，读取 paymentStatus 和 amountUsd
     * @return bool 退款状态或金额小于零时为 true
     */
    private function isRefund(array $row): bool
    {
        return in_array($row['paymentStatus'], ['refunded', 'reversed', 'chargeback', 'returned'], true)
            || ($row['amountUsd'] !== null && $row['amountUsd'] < 0);
    }

    /**
     * 累计分摊金额并补齐整个查询区间；普通与 Invoice 的佣金分别按现有 SA 阶梯计算后相加。
     *
     * @param array $rows 当前客服参与的已完成或退款订单，金额尚未逐行四舍五入
     * @param array $filters 日期闭区间 startDate/endDate
     * @return array summary 所有金额、比例保留两位小数；daily 每个日历日一行；缺失美元汇率的记录不计入财务指标
     */
    private function summarize(array $rows, array $filters): array
    {
        $daily = [];
        for ($day = CarbonImmutable::parse($filters['startDate']); $day->toDateString() <= $filters['endDate']; $day = $day->addDay()) {
            $date = $day->toDateString();
            $daily[$date] = ['date' => $date, 'sales' => 0.0, 'refunds' => 0.0, 'netSales' => 0.0];
        }
        $summary = ['sales' => 0.0, 'refunds' => 0.0, 'netSales' => 0.0, 'orders' => 0, 'refundOrders' => 0, 'missingRates' => 0];
        $netByKind = ['order' => 0.0, 'invoice' => 0.0];
        foreach ($rows as $row) {
            if ($row['myAmount'] === null) {
                $summary['missingRates']++;
                continue;
            }
            $amount = $row['myAmount'];
            $field = $row['refund'] ? 'refunds' : 'sales';
            $summary[$field] += abs($amount);
            $summary[$row['refund'] ? 'refundOrders' : 'orders']++;
            $summary['netSales'] += $amount;
            $netByKind[$row['kind']] += $amount;
            $daily[$row['date']][$field] += abs($amount);
            $daily[$row['date']]['netSales'] += $amount;
        }
        $summary['totalOrders'] = $summary['orders'] + $summary['refundOrders'];
        $summary['refundRateOrders'] = $summary['totalOrders'] ? $summary['refundOrders'] / $summary['totalOrders'] * 100 : 0.0;
        $summary['refundRateAmount'] = $summary['sales'] > 0 ? $summary['refunds'] / $summary['sales'] * 100 : 0.0;
        $summary['commissionUsd'] = SaSalesService::commission($netByKind['order']) + SaSalesService::commission($netByKind['invoice']);
        foreach (['sales', 'refunds', 'netSales', 'refundRateOrders', 'refundRateAmount', 'commissionUsd'] as $field) {
            $summary[$field] = round($summary[$field], 2);
        }
        foreach ($daily as &$day) {
            foreach (['sales', 'refunds', 'netSales'] as $field) {
                $day[$field] = round($day[$field], 2);
            }
        }
        unset($day);

        return ['summary' => $summary, 'daily' => array_values($daily)];
    }

    /**
     * 为当前页补充电话、渠道与收款信息，格式化金额并移除内部数据定位字段。
     *
     * @param array $rows 已在服务端分页的订单行，最多 100 条
     * @return array 可直接展示的个人订单列表，金额与分摊百分比保留两位小数
     */
    private function presentPage(array $rows): array
    {
        $orderIds = [];
        $invoiceIds = [];
        foreach ($rows as $row) {
            if ($row['sourceKind'] === 'invoice') {
                $invoiceIds[] = str_replace('invoice:', '', (string) $row['sourceId']);
            } else {
                $orderIds[] = $row['sourceId'];
            }
        }
        $contacts = $this->personalPerformanceDao->orderContacts($orderIds);
        $phones = $this->personalPerformanceDao->invoicePhones($invoiceIds);
        foreach ($rows as &$row) {
            $contact = $contacts[$row['sourceId']] ?? [];
            $row['phone'] = $row['sourceKind'] === 'invoice'
                ? ($phones[str_replace('invoice:', '', (string) $row['sourceId'])] ?? '') : ($contact['phone'] ?? '');
            $row['channel'] = ($contact['channel'] ?? '') ?: $row['website'];
            $row['paymentMethod'] = ($contact['paymentMethod'] ?? '') ?: ($row['account'] !== '' ? 'PayPal' : '');
            foreach (['orderAmount', 'myAmount', 'sharePercent'] as $field) {
                $row[$field] = $row[$field] === null ? null : round($row[$field], 2);
            }
            unset($row['sourceId'], $row['sourceKind']);
        }
        unset($row);

        return $rows;
    }
}
