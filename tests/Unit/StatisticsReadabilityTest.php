<?php

namespace Tests\Unit;

use App\Dao\SaSalesDao;
use App\Services\OrderManagementService;
use App\Services\OrderStatisticsService;
use App\Services\SaSalesService;
use PHPUnit\Framework\TestCase;

class StatisticsReadabilityTest extends TestCase
{
    public function test_missing_currency_and_zero_amount_keep_their_original_statistical_meaning(): void
    {
        $orders = $this->createStub(OrderManagementService::class);
        $orders->method('rows')->willReturn([
            $this->row(['currency' => null, 'amountUsd' => null]),
            $this->row(['amountUsd' => 0.0, 'amount' => 0.0]),
            $this->row(['classification' => 'invoice', 'amountUsd' => 200.0]),
        ]);
        $service = new OrderStatisticsService($orders);

        $overview = $service->statistics('overview', []);
        $this->assertSame(2, $overview['orders']);
        $this->assertSame(1, $overview['missingRates']);
        $this->assertSame(0.0, $overview['amountUsd']);

        $currencies = $service->statistics('currencies', []);
        $this->assertCount(2, $currencies['list']);
        $this->assertNull($currencies['list'][0]['key']);
        $this->assertSame(0, $currencies['list'][0]['share']);
    }

    public function test_staff_shares_use_the_full_total_and_monthly_trends_fill_empty_months(): void
    {
        $orders = $this->createStub(OrderManagementService::class);
        $orders->method('rows')->willReturn([
            $this->row(['staffAllocations' => [['staffCode' => 'AA', 'shareRatio' => 0.25], ['staffCode' => 'BB', 'shareRatio' => 0.75]]]),
            $this->row(['classification' => 'official', 'amountUsd' => 300.0]),
        ]);
        $service = new OrderStatisticsService($orders);

        $staff = $service->statistics('staff', []);
        $this->assertSame('BB', $staff['list'][0]['key']);
        $this->assertSame(0.75, $staff['list'][0]['orders']);
        $this->assertSame(18.75, $staff['list'][0]['share']);
        $this->assertSame(400.0, $staff['totals']['amountUsd']);

        $trend = $service->statistics('sales-trend', ['startDate' => '2026-08-15', 'endDate' => '2026-10-10', 'granularity' => 'month']);
        $this->assertSame(['2026-08', '2026-09', '2026-10'], array_column($trend['list'], 'key'));
        $this->assertSame(0.0, $trend['list'][1]['amountUsd']);
        $this->assertSame([], $trend['list'][1]['series']);
        $this->assertSame(['offline' => 1, 'official' => 1], $trend['list'][0]['orderSeries']);
        $this->assertSame([], $trend['list'][1]['orderSeries']);
    }

    public function test_empty_trends_cover_every_day_of_a_leap_month_and_every_month_of_a_year(): void
    {
        $orders = $this->createStub(OrderManagementService::class);
        $orders->method('rows')->willReturn([]);
        $service = new OrderStatisticsService($orders);
        $daily = $service->statistics('sales-trend', ['startDate' => '2024-02-01', 'endDate' => '2024-02-29', 'granularity' => 'day']);
        $this->assertCount(29, $daily['list']);
        $this->assertSame('2024-02-29', $daily['list'][28]['key']);
        $monthly = $service->statistics('sales-trend', ['startDate' => '2026-01-01', 'endDate' => '2026-12-31', 'granularity' => 'month']);
        $this->assertCount(12, $monthly['list']);
        $this->assertSame('2026-01', $monthly['list'][0]['key']);
        $this->assertSame('2026-12', $monthly['list'][11]['key']);
        $this->assertSame(0.0, $monthly['totals']['amountUsd']);
    }

    public function test_sales_rankings_preserve_shared_staff_refunds_unassigned_sales_and_invoice_separation(): void
    {
        $orders = $this->createStub(OrderManagementService::class);
        $orders->method('rows')->willReturn([
            $this->row(['staffAllocations' => [['staffCode' => 'AA', 'shareRatio' => 0.75], ['staffCode' => 'BB', 'shareRatio' => 0.25]]]),
            $this->row(['date' => '2026-08-02', 'paymentStatus' => 'refunded', 'amountUsd' => 40.0]),
            $this->row(['date' => '2026-08-03', 'classification' => 'official', 'amountUsd' => 20.0, 'staffAllocations' => [], 'recipientPaypal' => '']),
            $this->row(['amountUsd' => null]),
            $this->row(['paymentStatus' => 'pending', 'amountUsd' => 999.0]),
            $this->row(['kind' => 'invoice', 'classification' => 'invoice', 'amountUsd' => 200.0]),
        ]);
        $salesDao = $this->createStub(SaSalesDao::class);
        $salesDao->method('bounds')->willReturn([]);
        $report = (new SaSalesService($salesDao, $orders))->report([]);

        $this->assertSame(2, $report['metrics']['orders']);
        $this->assertSame(1, $report['metrics']['refundOrders']);
        $this->assertSame(1, $report['metrics']['missingRates']);
        $this->assertSame(80.0, $report['metrics']['netSales']);
        $this->assertSame(0.91, $report['metrics']['totalCommission']);
        $this->assertSame('AA', $report['metrics']['topSeller']);
        $this->assertSame(26.67, $report['metrics']['dailyAverage']);
        $this->assertSame(60.0, $report['metrics']['averageOrderValue']);
        $this->assertSame(['AA', 'BB', 'Unassigned'], array_column($report['employees'], 'name'));
        $this->assertSame([35.0, 25.0, 20.0], array_column($report['employees'], 'netSales'));
        $this->assertSame([2, 1, 1], array_column($report['employees'], 'activeDays'));
        $this->assertSame(0, $report['employees'][2]['commission']);
        $this->assertCount(3, $report['detail']);
        $this->assertSame(200.0, $report['invoiceSales']['metrics']['netSales']);
        $this->assertCount(1, $report['invoiceSales']['detail']);
    }

    public function test_commission_is_progressive_at_each_bracket_boundary(): void
    {
        foreach ([[-1, 0.0], [0, 0.0], [40000, 600.0], [60000, 1000.0], [80000, 1500.0], [90000, 1800.0]] as [$sales, $expected]) {
            $this->assertSame($expected, SaSalesService::commission($sales));
        }
    }

    public function test_empty_sa_period_has_no_employees_or_sales_shares(): void
    {
        $orders = $this->createStub(OrderManagementService::class);
        $orders->method('rows')->willReturn([]);
        $salesDao = $this->createStub(SaSalesDao::class);
        $salesDao->method('bounds')->willReturn([]);
        $report = (new SaSalesService($salesDao, $orders))->report([]);
        $this->assertSame([], $report['employees']);
        $this->assertSame([], $report['channels']);
        $this->assertSame([], $report['detail']);
        $this->assertSame([], $report['breakdowns']['employee']);
        $this->assertSame(0.0, $report['metrics']['netSales']);
    }

    private function row(array $overrides = []): array
    {
        return array_replace([
            'id' => 1,
            'kind' => 'order',
            'orderId' => 'ORDER-1',
            'customerFullName' => 'Buyer',
            'date' => '2026-08-15',
            'classification' => 'offline',
            'topInfluencer' => '',
            'paymentStatus' => 'completed',
            'currency' => 'USD',
            'amount' => 100.0,
            'amountUsd' => 100.0,
            'items' => 2,
            'recipientPaypal' => 'sales@example.test',
            'staffAllocations' => [['staffCode' => 'AA', 'shareRatio' => 1]],
        ], $overrides);
    }
}
