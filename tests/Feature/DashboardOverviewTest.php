<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\ExchangeRate;
use App\Models\InvoiceItem;
use App\Models\InvoiceOrder;
use App\Models\OnlineSpreadsheet;
use App\Models\Order;
use App\Models\PaypalAccount;
use App\Models\PaypalWithdrawal;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\DashboardOverviewService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Support\BusinessSchema;

class DashboardOverviewTest extends TestCase
{
    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RBAC_TEST_POSTGRES') !== '1') {
            $this->markTestSkipped('Use phpunit-dashboard.xml');
        }
        $this->schema = 'rbac_test_' . bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA ' . $this->schema);
        DB::statement('SET search_path TO ' . $this->schema);
        (require database_path('migrations/2026_09_04_180000_create_rbac.php'))->up();
        BusinessSchema::create(['orders','invoice_orders','invoice_items','invoice_staff_allocations','order_user_overrides','order_staff_allocations','exchange_rates','paypal_accounts','paypal_withdrawals','online_spreadsheets']);
        (require database_path('migrations/2026_09_06_180000_add_dashboard_overview_permissions.php'))->up();
        $admin = User::create(['username' => 'admin','display_name' => 'Admin','password_hash' => Hash::make('Test-123'),'active' => 1,'role_id' => 1]);
        $this->withHeader('Authorization', 'Bearer ' . ApiToken::issue($admin->id)['plain']);
        $this->travelTo(new \DateTimeImmutable('2026-09-05T18:30:00Z')); // September 6 in Shanghai.
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        if (isset($this->schema) && preg_match('/^rbac_test_[a-f0-9]{16}$/', $this->schema)) {
            DB::statement('SET search_path TO public');
            DB::statement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
        parent::tearDown();
    }

    private function order(array $data = []): Order
    {
        return Order::create(array_merge(['order_id' => 'O-' . bin2hex(random_bytes(4)),'visible_order_id' => random_int(1, 1000000),'customer_name' => 'Buyer','classification' => 'offline','order_time' => '2026-09-06T02:00:00Z','amount_original' => 100,'amount_usd' => 100,'currency' => 'USD','items_count' => 2,'product_name' => 'Bag','order_status' => 'completed','staff_code' => 'AA','version' => 1], $data));
    }

    public function test_default_today_uses_shanghai_midnight_and_status_filters(): void
    {
        $this->order(['order_time' => '2026-09-05T16:00:00Z']);
        $this->order(['order_time' => '2026-09-06T15:59:59.999999Z']);
        $this->order(['order_time' => '2026-09-06T16:00:00Z']);
        $this->order(['order_time' => '2026-09-05T15:59:59Z','amount_usd' => 50]);
        $this->order(['customer_name' => 'Test Buyer']);
        $this->order()->delete();
        $this->order(['order_status' => 'pending']);
        $this->order(['order_status' => 'refunded']);
        $this->getJson('/api/dashboard/overview')->assertOk()->assertJsonPath('data.range.startDate', '2026-09-06')
            ->assertJsonPath('data.range.endDate', '2026-09-06')->assertJsonPath('data.data.current.orders', 2)
            ->assertJsonPath('data.data.current.amountUsd', 200)->assertJsonPath('data.data.previous.amountUsd', 50)
            ->assertJsonPath('data.data.changes.amountUsd', 300)->assertJsonPath('data.data.statuses.pending', 1)
            ->assertJsonPath('data.data.statuses.refunded', 1);
    }

    public function test_invalid_ranges_are_rejected_and_single_date_is_supported(): void
    {
        foreach (['startDate=bad','startDate=2026-09-08&endDate=2026-09-01','startDate=2024-01-01&endDate=2026-09-01','granularity=hour','startDate=2026-02-30'] as $query) {
            $this->getJson('/api/dashboard/overview?' . $query)->assertUnprocessable();
        }
        $this->getJson('/api/dashboard/overview?endDate=2026-08-01')->assertOk()->assertJsonPath('data.range.startDate', '2026-08-01');
    }

    public function test_invoice_is_deduplicated_and_separate_from_headline_metrics(): void
    {
        $this->order();
        $this->order(['classification' => 'invoice','client_order_id' => 'INV-A']);
        $invoice = InvoiceOrder::create(['order_number' => 'INV-A','invoice_date' => '2026-09-06','invoice_status' => 'Paid','amount_usd' => 50]);
        InvoiceItem::create(['invoice_id' => $invoice->id,'product_name' => 'Item','quantity' => 3]);
        $this->getJson('/api/dashboard/overview')->assertOk()->assertJsonPath('data.data.current.orders', 1)->assertJsonPath('data.data.allOrders', 2);
        $this->getJson('/api/dashboard/categories')->assertOk()->assertJsonPath('data.data.totals.orders', 2)->assertJsonPath('data.data.totals.amountUsd', 150);
        $this->getJson('/api/dashboard/recent-orders')->assertOk()->assertJsonPath('data.data.total', 2);
    }

    public function test_empty_day_does_not_fallback_and_future_exchange_rates_are_not_used(): void
    {
        $this->order(['order_time' => '2026-08-01T02:00:00Z']);
        $this->getJson('/api/dashboard/overview')->assertOk()->assertJsonPath('data.data.current.amountUsd', 0)->assertJsonPath('data.data.changes.amountUsd', null);
        ExchangeRate::create(['currency' => 'EUR','effective_date' => '2026-09-01','rate_to_usd' => 1.2]);
        ExchangeRate::create(['currency' => 'EUR','effective_date' => '2026-10-01','rate_to_usd' => 2]);
        $this->order(['currency' => 'EUR','amount_usd' => null]);
        $this->order(['currency' => 'XYZ','amount_usd' => null]);
        $this->getJson('/api/dashboard/overview')->assertOk()->assertJsonPath('data.data.current.amountUsd', 120)->assertJsonPath('data.data.current.missingRates', 1)->assertJsonPath('data.data.current.averageOrderValue', 120);
        $this->getJson('/api/dashboard/exchange-rates')->assertOk()->assertJsonPath('data.data.list.0.rateToUsd', 1.2);
    }

    public function test_each_module_permission_is_independent_and_seed_is_idempotent(): void
    {
        $count = Permission::count();
        (require database_path('migrations/2026_09_06_180000_add_dashboard_overview_permissions.php'))->up();
        $this->assertSame($count, Permission::count());
        $role = Role::create(['name' => 'Overview reader','code' => 'overview_reader','status' => 1]);
        $role->permissions()->sync(Permission::where('code', 'dashboard.overview.overview')->pluck('id'));
        $user = User::create(['username' => 'reader','display_name' => 'Reader','password_hash' => Hash::make('Test-123'),'active' => 1,'role_id' => $role->id]);
        $this->withHeader('Authorization', 'Bearer ' . ApiToken::issue($user->id)['plain']);
        $this->getJson('/api/dashboard/overview')->assertOk();
        foreach (array_diff(DashboardOverviewService::MODULES, ['overview']) as $module) {
            $this->getJson('/api/dashboard/' . $module)->assertForbidden();
        }
        $this->withHeader('Authorization', 'Bearer invalid');
        $this->getJson('/api/dashboard/overview')->assertUnauthorized();
    }

    public function test_trends_fill_missing_days_and_shared_staff_amounts_match_source(): void
    {
        $this->order(['raw' => ['staffAllocations' => [['staffCode' => 'AA','percent' => 25],['staffCode' => 'BB','percent' => 75]]]]);
        $this->getJson('/api/dashboard/sales-trend?startDate=2026-09-05&endDate=2026-09-07')->assertOk()->assertJsonCount(30, 'data.data.list')->assertJsonPath('data.data.list.0.orders', 0)->assertJsonPath('data.data.list.5.amountUsd', 100);
        $this->getJson('/api/dashboard/sales-trend?startDate=2026-08-01&endDate=2026-09-07&granularity=month')->assertOk()->assertJsonCount(12, 'data.data.list');
        $this->getJson('/api/dashboard/staff')->assertOk()->assertJsonPath('data.data.list.0.key', 'BB')->assertJsonPath('data.data.list.0.orders', 0.75)->assertJsonPath('data.data.list.0.amountUsd', 75);
    }

    public function test_daily_trend_uses_full_month_amounts_for_today_yesterday_and_another_month(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-09-14T02:00:00Z'));
        foreach ([
            ['2026-07-31T15:59:59Z', 999],
            ['2026-07-31T16:00:00Z', 10],
            ['2026-08-30T02:00:00Z', 20],
            ['2026-08-31T15:59:59Z', 30],
            ['2026-08-31T16:00:00Z', 40],
            ['2026-09-13T02:00:00Z', 50],
            ['2026-09-30T15:59:59Z', 60],
            ['2026-09-30T16:00:00Z', 999],
        ] as [$date, $amount]) {
            $this->order(['order_time' => $date, 'amount_usd' => $amount]);
        }

        foreach (['', '?startDate=2026-09-13&endDate=2026-09-13&granularity=day'] as $query) {
            $this->getJson('/api/dashboard/sales-trend' . $query)
                ->assertOk()
                ->assertJsonPath('data.range.startDate', '2026-09-01')
                ->assertJsonPath('data.range.endDate', '2026-09-30')
                ->assertJsonCount(30, 'data.data.list')
                ->assertJsonPath('data.data.list.0.key', '2026-09-01')
                ->assertJsonPath('data.data.list.0.amountUsd', 40)
                ->assertJsonPath('data.data.list.1.amountUsd', 0)
                ->assertJsonPath('data.data.list.12.amountUsd', 50)
                ->assertJsonPath('data.data.list.29.key', '2026-09-30')
                ->assertJsonPath('data.data.list.29.amountUsd', 60)
                ->assertJsonPath('data.data.totals.amountUsd', 150);
        }

        $this->getJson('/api/dashboard/sales-trend?startDate=2026-08-30&endDate=2026-08-30&granularity=day')
            ->assertOk()
            ->assertJsonPath('data.range.startDate', '2026-08-01')
            ->assertJsonPath('data.range.endDate', '2026-08-31')
            ->assertJsonCount(31, 'data.data.list')
            ->assertJsonPath('data.data.list.0.amountUsd', 10)
            ->assertJsonPath('data.data.list.29.amountUsd', 20)
            ->assertJsonPath('data.data.list.30.key', '2026-08-31')
            ->assertJsonPath('data.data.list.30.amountUsd', 30)
            ->assertJsonPath('data.data.totals.amountUsd', 60);

        $query = '?startDate=2026-09-13&endDate=2026-09-13&granularity=day';
        foreach (['overview', 'categories', 'staff', 'influencers', 'recent-orders'] as $module) {
            $this->getJson('/api/dashboard/' . $module . $query)
                ->assertOk()
                ->assertJsonPath('data.range.startDate', '2026-09-13')
                ->assertJsonPath('data.range.endDate', '2026-09-13');
        }
        $this->getJson('/api/dashboard/overview' . $query)->assertJsonPath('data.data.current.amountUsd', 50);
    }

    public function test_daily_trend_fills_leap_months_and_cross_month_ranges(): void
    {
        foreach ([
            ['2024-02-10', '2024-02-10', '2024-02-01', '2024-02-29', 29],
            ['2026-08-30', '2026-09-13', '2026-08-01', '2026-09-30', 61],
            ['2025-12-24', '2026-12-23', '2025-12-01', '2026-12-31', 396],
        ] as [$start, $end, $firstDay, $lastDay, $days]) {
            $this->getJson('/api/dashboard/sales-trend?' . http_build_query([
                'startDate' => $start,
                'endDate' => $end,
                'granularity' => 'day',
            ]))
                ->assertOk()
                ->assertJsonPath('data.range.startDate', $firstDay)
                ->assertJsonPath('data.range.endDate', $lastDay)
                ->assertJsonCount($days, 'data.data.list')
                ->assertJsonPath('data.data.list.0.key', $firstDay)
                ->assertJsonPath('data.data.list.' . ($days - 1) . '.key', $lastDay)
                ->assertJsonPath('data.data.totals.amountUsd', 0);
        }
    }

    public function test_monthly_trend_uses_full_year_amounts_while_other_modules_keep_selected_dates(): void
    {
        $this->order(['order_time' => '2025-12-31T15:59:59Z', 'amount_usd' => 999]);
        $this->order(['order_time' => '2025-12-31T16:00:00Z', 'amount_usd' => 10]);
        $this->order(['order_time' => '2026-05-10T02:00:00Z', 'amount_usd' => 20, 'classification' => 'top_influencer', 'influencer_name' => 'Example']);
        $this->order(['order_time' => '2026-12-31T15:59:59Z', 'amount_usd' => 30]);
        $this->order(['order_time' => '2026-12-31T16:00:00Z', 'amount_usd' => 999]);
        $query = '?startDate=2026-05-10&endDate=2026-05-10&granularity=month';

        $this->getJson('/api/dashboard/sales-trend' . $query)
            ->assertOk()
            ->assertJsonPath('data.range.startDate', '2026-01-01')
            ->assertJsonPath('data.range.endDate', '2026-12-31')
            ->assertJsonCount(12, 'data.data.list')
            ->assertJsonPath('data.data.list.0.key', '2026-01')
            ->assertJsonPath('data.data.list.0.amountUsd', 10)
            ->assertJsonPath('data.data.list.1.amountUsd', 0)
            ->assertJsonPath('data.data.list.4.amountUsd', 20)
            ->assertJsonPath('data.data.list.11.key', '2026-12')
            ->assertJsonPath('data.data.list.11.amountUsd', 30)
            ->assertJsonPath('data.data.totals.amountUsd', 60);

        foreach (['overview', 'categories', 'staff', 'influencers', 'recent-orders'] as $module) {
            $this->getJson('/api/dashboard/' . $module . $query)
                ->assertOk()
                ->assertJsonPath('data.range.startDate', '2026-05-10')
                ->assertJsonPath('data.range.endDate', '2026-05-10');
        }
        $this->getJson('/api/dashboard/overview' . $query)->assertJsonPath('data.data.current.amountUsd', 20);
        $this->getJson('/api/dashboard/sales-trend?startDate=2026-05-10&endDate=2026-05-10&granularity=day')
            ->assertOk()->assertJsonCount(31, 'data.data.list')->assertJsonPath('data.data.totals.amountUsd', 20);
    }

    public function test_monthly_cross_year_filter_covers_all_months_of_both_years(): void
    {
        $this->order(['order_time' => '2024-06-01T02:00:00Z', 'amount_usd' => 999]);
        $this->order(['order_time' => '2025-01-25T02:00:00Z', 'amount_usd' => 40]);
        $this->order(['order_time' => '2026-11-25T02:00:00Z', 'amount_usd' => 50]);

        $this->getJson('/api/dashboard/sales-trend?startDate=2025-12-24&endDate=2026-01-04&granularity=month')
            ->assertOk()
            ->assertJsonPath('data.range.startDate', '2025-01-01')
            ->assertJsonPath('data.range.endDate', '2026-12-31')
            ->assertJsonCount(24, 'data.data.list')
            ->assertJsonPath('data.data.list.0.key', '2025-01')
            ->assertJsonPath('data.data.list.0.amountUsd', 40)
            ->assertJsonPath('data.data.list.22.key', '2026-11')
            ->assertJsonPath('data.data.list.22.amountUsd', 50)
            ->assertJsonPath('data.data.list.23.key', '2026-12')
            ->assertJsonPath('data.data.totals.amountUsd', 90);
    }

    public function test_paypal_uses_selected_dates_and_status_returns_real_coverage(): void
    {
        $account = PaypalAccount::create(['email' => 'sales@example.test','account_name' => 'Sales','active' => true]);
        $this->order(['receiving_paypal' => $account->email]);
        $this->order(['receiving_paypal' => $account->email,'order_time' => '2026-08-01T02:00:00Z']);
        PaypalWithdrawal::create(['account_id' => $account->id,'amount' => 20,'withdrawn_at' => '2026-09-06']);
        PaypalWithdrawal::create(['account_id' => $account->id,'amount' => 30,'withdrawn_at' => '2026-09-05']);
        $this->getJson('/api/dashboard/paypal')->assertOk()->assertJsonPath('data.data.received', 100)->assertJsonPath('data.data.withdrawn', 20);
        $this->getJson('/api/dashboard/system-status')->assertOk()->assertJsonPath('data.data.dataThrough', '2026-09-06')->assertJsonPath('data.data.orderRecords', 2)->assertJsonPath('data.data.collectorState', null);
        OnlineSpreadsheet::create(['source_key' => 'demo','department' => 'operations','provider' => 'WPS','title_zh' => 'Demo','title_en' => 'Demo','description_zh' => '','description_en' => '','url' => 'https://example.test','active' => true]);
        $this->getJson('/api/dashboard/spreadsheets')->assertOk()->assertJsonCount(1, 'data.data.list');
    }

    public function test_recent_orders_are_bounded_ordered_and_do_not_expose_raw_payloads(): void
    {
        for ($i = 0;$i < 12;$i++) {
            $this->order(['order_time' => sprintf('2026-09-06T02:%02d:00Z', $i),'raw' => ['private' => 'internal-data']]);
        }
        $this->getJson('/api/dashboard/recent-orders')->assertOk()->assertJsonPath('data.data.total',12)->assertJsonCount(10,'data.data.list')->assertJsonMissingPath('data.data.list.0.raw');
    }
}
