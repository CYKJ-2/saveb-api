<?php

namespace Tests\Unit;

use App\Dao\BusinessOperationLogDao;
use App\Dao\InfluencerDao;
use App\Dao\PaypalDao;
use App\Dao\ProcurementDao;
use App\Dao\SaSalesDao;
use App\Models\Influencer;
use App\Models\ProcurementTask;
use App\Services\InfluencerService;
use App\Services\OrderManagementService;
use App\Services\OrderStatisticsService;
use App\Services\PaypalService;
use App\Services\ProcurementService;
use App\Services\SaSalesService;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

class MoneyPrecisionTest extends TestCase
{
    public function test_every_statistics_dimension_rounds_nested_category_amounts_after_summing(): void
    {
        $orders = $this->createStub(OrderManagementService::class);
        $orders->method('rows')->willReturn($this->orders());
        $statistics = new OrderStatisticsService($orders);
        $range = ['startDate' => '2026-09-01', 'endDate' => '2026-09-30'];

        foreach (['overview', 'currencies', 'categories', 'influencers', 'staff', 'sales-trend'] as $module) {
            $report = $statistics->statistics($module, $range);
            $this->assertMoneyPrecision($report, $module);
        }
        foreach (['day', 'month'] as $granularity) {
            $report = $statistics->statistics('sales-trend', $range + ['granularity' => $granularity]);
            $key = $granularity === 'day' ? '2026-09-07' : '2026-09';
            $group = array_column($report['list'], null, 'key')[$key];
            $this->assertSame(0.3, $group['series']['top_influencer']);
            $this->assertSame(0.3, $group['series']['invoice']);
            $this->assertSame(2, $group['orderSeries']['top_influencer']);
            $this->assertSame(2, $group['orderSeries']['invoice']);
            $this->assertMoneyPrecision($report, $granularity);
        }
        // 六笔线下订单各分摊 1/3 分；逐单舍入会变成零，应在累计后得到 0.02。
        $staff = $statistics->statistics('staff', $range);
        $employees = array_column($staff['list'], null, 'key');
        $this->assertSame(0.02, $employees['AA']['amountUsd']);
    }

    public function test_sa_sales_refunds_commissions_and_invoice_statistics_keep_two_decimal_amounts(): void
    {
        $rows = $this->orders();
        $rows[] = array_replace($rows[0], ['paymentStatus' => 'refunded', 'amountUsd' => 0.1]);
        $orders = $this->createStub(OrderManagementService::class);
        $orders->method('rows')->willReturn($rows);
        $dao = $this->createStub(SaSalesDao::class);
        $dao->method('bounds')->willReturn([]);
        $report = (new SaSalesService($dao, $orders))->report([]);

        $this->assertSame(0.36, $report['metrics']['positiveSales']);
        $this->assertSame(0.1, $report['metrics']['refundAmount']);
        $this->assertSame(0.26, $report['metrics']['netSales']);
        $this->assertSame(0.3, $report['invoiceSales']['metrics']['netSales']);
        $this->assertSame(1 / 3, $report['detail'][0]['staffAllocations'][0]['shareRatio']);
        $this->assertMoneyPrecision($report, 'sa-sales');
    }

    public function test_paypal_activity_used_for_balance_baselines_does_not_keep_float_tails(): void
    {
        $activityDao = $this->createStub(\App\Dao\PaypalActivityDao::class);
        $activityDao->method('orders')->willReturn([
            new \App\Models\Order(['id' => 1, 'client_order_id' => 'A', 'receiving_paypal' => 'sales@example.test', 'amount_usd' => 0.1]),
            new \App\Models\Order(['id' => 2, 'client_order_id' => 'B', 'receiving_paypal' => 'sales@example.test', 'amount_usd' => 0.2]),
        ]);
        $activityDao->method('daysAfter')->willReturn([]);
        $activityDao->method('rates')->willReturn(['USD' => 1]);
        $service = new PaypalService(
            $this->createStub(PaypalDao::class),
            new \App\Services\PaypalActivityService($activityDao),
            $this->createStub(BusinessOperationLogDao::class),
            $this->createStub(\App\Services\PaypalLegacyService::class),
        );

        $activity = $service->activity();
        $this->assertSame(0.3, $activity['sales@example.test']['amount']);
        $this->assertCount(2, $activity['sales@example.test']['orders']);
    }

    public function test_influencer_json_amounts_are_rounded_without_changing_existing_value_types(): void
    {
        $profile = new Influencer();
        $profile->setRawAttributes(['profile' => json_encode([
            'sampleValue' => 0.1 + 0.2,
            'commissionAmount' => '12.34567',
            'sampleQuantity' => 7,
        ])]);
        $dao = $this->createStub(InfluencerDao::class);
        $dao->method('domains')->willReturn(new Collection());
        $dao->method('profiles')->willReturn(new Collection(['Precision' => $profile]));
        $orders = $this->createStub(OrderManagementService::class);
        $orders->method('rows')->willReturn(array_slice($this->orders(), 0, 2));
        $service = new InfluencerService($dao, $orders, $this->createStub(BusinessOperationLogDao::class));

        $row = $service->sales([])[0];
        $this->assertSame(0.3, $row['sampleValue']);
        $this->assertSame('12.35', $row['commissionAmount']);
        $this->assertSame(7, $row['sampleQuantity']);
        $this->assertSame(0.3, $row['amountUsd']);
    }

    public function test_procurement_rounds_historical_snapshot_amounts_and_preserves_nulls(): void
    {
        $tasks = [];
        foreach ([0.1 + 0.2, '12.34567', null] as $index => $amount) {
            $task = new ProcurementTask();
            $task->setRawAttributes([
                'id' => $index + 1,
                'purchase_status' => 'pending_purchase',
                'cost' => '19.99',
                'raw' => json_encode(['amount' => $amount, 'date' => '2026-09-07']),
            ]);
            $task->setRelation('warehouse', null);
            $tasks[] = $task;
        }
        $dao = $this->createStub(ProcurementDao::class);
        $dao->method('removed')->willReturn([]);
        $dao->method('all')->willReturn(new Collection($tasks));
        $orders = $this->createStub(OrderManagementService::class);
        $orders->method('rows')->willReturn([]);
        $service = new ProcurementService($dao, $orders, $this->createStub(BusinessOperationLogDao::class));

        $rows = $service->rows([]);
        $this->assertSame([0.3, '12.35', null], array_column($rows, 'amount'));
        $this->assertSame(['19.99', '19.99', '19.99'], array_column($rows, 'cost'));
    }

    private function orders(): array
    {
        $rows = [];
        foreach (['top_influencer', 'invoice'] as $category) {
            foreach ([0.1, 0.2, 0.01, 0.01, 0.01] as $index => $amount) {
                // 小额多笔分摊也要覆盖；额外三笔归入另一分类。
                $classification = $index < 2 ? $category : 'offline';
                $rows[] = [
                    'id' => count($rows) + 1,
                    'kind' => $classification === 'invoice' ? 'invoice' : 'order',
                    'orderId' => 'PRECISION-' . count($rows),
                    'customerFullName' => 'Precision fixture',
                    'date' => '2026-09-07',
                    'classification' => $classification,
                    'topInfluencer' => 'Precision',
                    'paymentStatus' => 'completed',
                    'currency' => 'USD',
                    'amount' => $amount,
                    'amountUsd' => $amount,
                    'items' => 1,
                    'recipientPaypal' => 'sales@example.test',
                    'staffAllocations' => [['staffCode' => 'AA', 'shareRatio' => 1 / 3], ['staffCode' => 'BB', 'shareRatio' => 2 / 3]],
                ];
            }
        }

        return $rows;
    }

    private function assertMoneyPrecision(array $data, string $path, bool $categorySeries = false): void
    {
        $moneyFields = ['amount', 'amountUsd', 'amountOriginal', 'positiveSales', 'refundAmount', 'netSales', 'totalCommission', 'dailyAverage', 'averageOrderValue', 'sales', 'refunds', 'commission'];
        foreach ($data as $key => $value) {
            $field = $path . '.' . $key;
            if (is_array($value)) {
                $this->assertMoneyPrecision($value, $field, $key === 'series');
            } elseif ($categorySeries || in_array($key, $moneyFields, true)) {
                $this->assertMatchesRegularExpression('/^-?\d+(?:\.\d{1,2})?$/', json_encode($value, JSON_THROW_ON_ERROR), $field);
            }
        }
    }
}
