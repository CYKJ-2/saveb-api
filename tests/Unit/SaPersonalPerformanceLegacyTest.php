<?php

namespace Tests\Unit;

use App\Dao\OrderManagementDao;
use App\Dao\SaPersonalPerformanceDao;
use App\Services\OrderManagementService;
use App\Services\SaPersonalPerformanceService;
use PHPUnit\Framework\TestCase;

/** 同一批夹具已由旧 html 的 renderPersonalDetail 函数独立核对。 */
class SaPersonalPerformanceLegacyTest extends TestCase
{
    public function test_personal_statistics_match_legacy_reference_cases(): void
    {
        $cases = json_decode(file_get_contents(__DIR__ . '/../Fixtures/sa-personal-legacy.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            $source = [];
            foreach ($case['records'] as $index => $record) {
                $source[] = [
                    'id' => $record['kind'] === 'invoice' ? 'invoice:' . ($index + 1) : $index + 1,
                    'kind' => $record['kind'], 'classification' => $record['kind'] === 'invoice' ? 'invoice' : 'offline',
                    'orderId' => 'SOURCE-' . $index, 'date' => $record['date'], 'customerFullName' => $record['customer'],
                    'clientSite' => '', 'recipientPaypal' => '', 'paymentStatus' => 'completed',
                    'amountUsd' => $record['totalAmount'],
                    'staffAllocations' => [['staffCode' => 'AA', 'shareRatio' => $record['shareRatio']]],
                ];
            }
            $orders = $this->createStub(OrderManagementService::class);
            $orders->method('rows')->willReturn($source);
            $service = new SaPersonalPerformanceService($orders, $this->createStub(OrderManagementDao::class), $this->createStub(SaPersonalPerformanceDao::class));
            $report = $service->report(['staffCode' => 'AA', 'startDate' => '2026-08-01', 'endDate' => '2026-08-31']);
            foreach ($case['expected'] as $field => $expected) {
                $this->assertEquals($expected, $report['summary'][$field], $case['name'] . ':' . $field);
            }
            $this->assertCount(count($case['records']), $report['orders']['list'], '去重不能删除金额及明细');
            $this->assertCount(31, $report['daily']);
            $this->assertEquals($case['expected']['netSales'], array_sum(array_column($report['daily'], 'netSales')));
            $this->assertEquals($case['expected']['sales'], array_sum(array_column($report['daily'], 'sales')));
            $this->assertEquals($case['expected']['refunds'], array_sum(array_column($report['daily'], 'refunds')));
        }
    }
}
