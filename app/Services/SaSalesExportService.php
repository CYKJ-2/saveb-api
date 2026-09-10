<?php

namespace App\Services;

use App\Common\ExportHeaders;

/** 保持原平台 reportCsv 的分段、表头顺序与指标口径。 */
class SaSalesExportService
{
    /**
     * 注入 SA 销售导出处理所需的依赖。
     *
     * @param  SaSalesService  $saSalesService  SA 销售业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private SaSalesService $saSalesService)
    {
    }

    /**
     * 根据当前语言生成 SA 报表 CSV 的分区标题、表头和数据行。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件；本方法读取 startDate、endDate
     * @param  string  $locale  当前界面语言，如 zh-CN 或 en-US
     * @return iterable 按 CSV 输出顺序生成的分区、表头和单元格行
     * @see SaSalesService::report()
     */
    public function lines(array $filters, string $locale): iterable
    {
        $report = $this->saSalesService->report($filters + ['includeDetails' => true]);
        $translate = fn ($labels) => array_map(fn ($label) => ExportHeaders::translate($label, $locale), $labels);
        yield $translate(['SA Sales Performance Report']);
        yield [ExportHeaders::translate('Date Range', $locale), $filters['startDate'], $filters['endDate']];
        yield [ExportHeaders::translate('Data Through', $locale), $report['source']['dataThrough'] ?? ''];
        yield [ExportHeaders::translate('Refreshed At', $locale), $report['source']['refreshedAt'] ?? ''];
        yield [];
        yield $translate(['Summary']);
        yield $translate(['Orders', 'Refund Orders', 'Net Sales USD', 'Total Commission USD', 'Average Order Value USD', 'Active Days', 'Daily Average USD', 'Top Seller']);
        yield $this->cells($report['metrics'], ['orders', 'refundOrders', 'netSales', 'totalCommission', 'averageOrderValue', 'activeDays', 'dailyAverage', 'topSeller']);
        foreach (['Employee Ranking' => $report['employees'], 'Invoice Employee Ranking' => $report['invoiceSales']['employees']] as $title => $rows) {
            yield [];
            yield $translate([$title]);
            yield $translate(['Rank', 'Employee', 'Orders', 'Refund Orders', 'Net Sales USD', 'Commission USD', 'Daily Average USD', 'Active Days']);
            foreach ($rows as $row) {
                yield $this->cells($row, ['rank', 'name', 'orders', 'refundOrders', 'netSales', 'commission', 'dailyAverage', 'activeDays']);
            }
        }
        yield [];
        yield $translate(['Channel Summary']);
        yield $translate(['Channel', 'Orders', 'Sales USD', 'Refunds USD', 'Net Sales USD', 'Positive Sales Share %']);
        foreach ($report['channels'] as $row) {
            yield $this->cells($row, ['name', 'orders', 'sales', 'refunds', 'netSales', 'sharePercent']);
        }
        yield [];
        yield $translate(['Daily Summary']);
        yield $translate(['Date', 'Orders', 'Refund Orders', 'Sales USD', 'Refunds USD', 'Net Sales USD']);
        foreach ($report['daily'] as $row) {
            yield $this->cells($row, ['date', 'orders', 'refundOrders', 'sales', 'refunds', 'netSales']);
        }
        yield [];
        yield $translate(['Order Detail']);
        yield $translate(['Stable Identity', 'Date Ordered', 'Customer', 'Amount USD', 'Employee', 'Channel', 'Payment Method', 'Payment Account', 'Refund']);
        foreach ($report['detail'] as $row) {
            $row['refund'] = $row['refund'] ? ($locale === 'en-US' ? 'Yes' : '是') : ($locale === 'en-US' ? 'No' : '否');
            yield $this->cells($row, ['identity', 'date', 'customer', 'amountUsd', 'staff', 'channel', 'paymentMethod', 'account', 'refund']);
        }
    }

    /**
     * 按导出字段顺序提取单行数据，空值输出为空单元格。
     *
     * @param  array  $row  SA 销售导出单条记录
     * @param  array  $fields  查询或输出的字段列表
     * @return array 与 fields 顺序一致的单元格数组
     */
    private function cells(array $row, array $fields): array
    {
        $money = ['sales', 'refunds', 'netSales', 'totalCommission', 'averageOrderValue', 'dailyAverage', 'commission', 'amountUsd', 'sharePercent'];

        return array_map(fn ($field) => in_array($field, $money, true) && is_numeric($row[$field] ?? null)
            ? number_format((float) $row[$field], 2, '.', '')
            : ($row[$field] ?? ''), $fields);
    }
}
