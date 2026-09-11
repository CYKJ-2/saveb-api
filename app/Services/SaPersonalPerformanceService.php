<?php

namespace App\Services;

use App\Common\PageResult;
use App\Dao\OrderManagementDao;
use App\Dao\SaPersonalPerformanceDao;
use Carbon\CarbonImmutable;

/** 统一订单转换为个人分摊记录后，按旧 html 的 renderPersonalDetail 口径统计美元业绩。 */
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
                'account' => $row['recipientPaypal'] ?? '', 'refund' => $amount === null ? $refund : $amount < 0,
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
     * 沿用旧个人详情：金额累加全部分摊记录，单数独立去重，合计净销售额统一计算阶梯佣金。
     *
     * @param array $rows 当前客服参与的已完成或退款订单，金额尚未逐行四舍五入
     * @param array $filters 日期闭区间 startDate/endDate
     * @return array summary 中 totalOrders/orders 均为去重成交单数，refundOrders 为去重退款单数；daily 补齐日历日，金额、百分比保留两位小数
     */
    private function summarize(array $rows, array $filters): array
    {
        $daily = [];
        for ($day = CarbonImmutable::parse($filters['startDate']); $day->toDateString() <= $filters['endDate']; $day = $day->addDay()) {
            $date = $day->toDateString();
            $daily[$date] = ['date' => $date, 'sales' => 0.0, 'refunds' => 0.0, 'netSales' => 0.0];
        }
        $summary = ['sales' => 0.0, 'refunds' => 0.0, 'netSales' => 0.0, 'orders' => 0, 'refundOrders' => 0, 'missingRates' => 0];
        $saleKeys = [];
        $refundKeys = [];
        foreach ($rows as $row) {
            if ($row['myAmount'] === null) {
                $summary['missingRates']++;
                continue;
            }
            $amount = $row['myAmount'];
            $refund = $amount < 0;
            $field = $refund ? 'refunds' : 'sales';
            $summary[$field] += abs($amount);
            $summary['netSales'] += $amount;
            // 旧版只对单数去重；金额和明细仍保留全部记录，不能在这里对销售金额去重。
            $key = $this->orderCountKey($row);
            if ($refund) {
                $refundKeys[$key] = true;
            } else {
                $saleKeys[$key] = true;
            }
            $daily[$row['date']][$field] += abs($amount);
            $daily[$row['date']]['netSales'] += $amount;
        }
        $summary['orders'] = count($saleKeys);
        $summary['refundOrders'] = count($refundKeys);
        // 旧页面“总单数”绑定 orderCount，仅含非负销售记录，不包含退款单数。
        $summary['totalOrders'] = $summary['orders'];
        $summary['refundRateOrders'] = $summary['orders'] > 0
            ? $summary['refundOrders'] / ($summary['orders'] + $summary['refundOrders']) * 100 : 0.0;
        $summary['refundRateAmount'] = $summary['sales'] > 0
            ? $summary['refunds'] / ($summary['sales'] + $summary['refunds']) * 100 : 0.0;
        // 个人详情合并所有渠道的净额计提，与 SA 两个排行榜分别计提的用途不同。
        $summary['commissionUsd'] = SaSalesService::commission($summary['netSales']);
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
     * 按旧版“日期、客户、订单总金额”建立单数去重键；总金额为零时回退到个人金额。
     *
     * @param array $row 个人分摊记录，读取 date/customer/orderAmount/myAmount，不使用订单 ID 或渠道
     * @return string 元组编码的去重键；客户名保持原样，避免改变旧版大小写及空格匹配规则
     */
    private function orderCountKey(array $row): string
    {
        return json_encode([
            $row['date'], $row['customer'], $row['orderAmount'] ?: $row['myAmount'],
        ], JSON_THROW_ON_ERROR);
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
