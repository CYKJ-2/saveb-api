<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Attachment;
use App\Models\BusinessOperationLog;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Support\BusinessSchema;

class WorkbenchTest extends TestCase
{
    public function test_personal_performance_uses_staff_shares_refunds_and_invoice_deduplication(): void
    {
        $this->order(['amount_usd' => 100, 'raw' => ['staffAllocations' => [
            ['staffCode' => 'AA', 'percent' => 25], ['staffCode' => 'BB', 'percent' => 75],
        ], 'phoneNumber' => '123456', 'platform' => 'Retail', 'paymentMethod' => 'Card']]);
        $this->order(['amount_usd' => 40, 'order_status' => 'refunded']);
        $invoice = \App\Models\InvoiceOrder::create(['order_number' => 'PERSONAL-I', 'invoice_date' => '2026-08-02', 'order_date' => '2026-08-02',
            'invoice_status' => 'Paid', 'amount_usd' => 200, 'phone_number' => '789012']);
        $refund = \App\Models\InvoiceOrder::create(['order_number' => 'PERSONAL-R', 'invoice_date' => '2026-08-03', 'order_date' => '2026-08-03',
            'invoice_status' => 'refunded', 'amount_usd' => 20]);
        foreach ([$invoice, $refund] as $item) {
            foreach (['AA', 'BB'] as $code) {
                \App\Models\InvoiceStaffAllocation::create(['invoice_id' => $item->id, 'staff_code' => $code, 'share_ratio' => .5]);
            }
        }
        $this->order(['classification' => 'invoice', 'client_order_id' => 'PERSONAL-I', 'amount_usd' => 200]);
        $this->order(['order_status' => 'pending']);
        $this->order(['customer_name' => 'Test Buyer']);
        $this->order(['staff_code' => 'AAA']);
        $report = $this->getJson('/api/workbench/sa-sales/personal/report?staffCode=aa&startDate=2026-08-01&endDate=2026-08-31')
            ->assertOk()->assertJsonPath('data.range.staffCode', 'AA')
            ->assertJsonPath('data.summary.sales', 125)->assertJsonPath('data.summary.refunds', 50)
            ->assertJsonPath('data.summary.netSales', 75)->assertJsonPath('data.summary.totalOrders', 2)
            ->assertJsonPath('data.summary.orders', 2)->assertJsonPath('data.summary.refundOrders', 2)
            ->assertJsonPath('data.summary.refundRateOrders', 50)->assertJsonPath('data.summary.refundRateAmount', 28.57)
            ->assertJsonPath('data.summary.commissionUsd', 1.13)
            ->assertJsonCount(31, 'data.daily')->assertJsonPath('data.daily.30.date', '2026-08-31')
            ->assertJsonPath('data.daily.30.sales', 0)->assertJsonPath('data.orders.total', 4)->json('data');
        $this->assertEquals(75, array_sum(array_column($report['daily'], 'netSales')));
        $orders = collect($report['orders']['list']);
        $card = $orders->firstWhere('phone', '123456');
        $this->assertEquals(25, $card['myAmount']);
        $this->assertEquals(25, $card['sharePercent']);
        $this->assertSame('Card', $card['paymentMethod']);
        $this->assertSame('Retail', $card['channel']);
        $this->assertSame('789012', $orders->firstWhere('orderId', 'PERSONAL-I')['phone']);
        $this->assertSame('invoice:' . $invoice->id, $orders->firstWhere('orderId', 'PERSONAL-I')['id']);
        $this->assertEquals(-10, $orders->firstWhere('orderId', 'PERSONAL-R')['myAmount']);
    }

    public function test_personal_performance_pagination_does_not_truncate_totals_and_can_skip_summary(): void
    {
        for ($index = 0; $index < 25; $index++) {
            $this->order(['amount_usd' => 20, 'raw' => ['staffAllocations' => [
                ['staffCode' => 'AA', 'percent' => 50], ['staffCode' => 'BB', 'percent' => 50],
            ]]]);
        }
        $url = '/api/workbench/sa-sales/personal/report?staffCode=AA&startDate=2026-08-01&endDate=2026-08-31';
        $first = $this->getJson($url)->assertOk()->assertJsonCount(20, 'data.orders.list')
            ->assertJsonPath('data.orders.total', 25)->assertJsonPath('data.summary.sales', 250)
            ->assertJsonPath('data.summary.totalOrders', 1)->json('data');
        $last = $this->getJson($url . '&page=2&includeSummary=0')->assertOk()
            ->assertJsonCount(5, 'data.orders.list')->assertJsonPath('data.summary', null)->assertJsonPath('data.daily', [])->json('data');
        $this->assertCount(25, array_unique(array_merge(array_column($first['orders']['list'], 'id'), array_column($last['orders']['list'], 'id'))));
        $this->getJson($url . '&page=999')->assertOk()->assertJsonPath('data.orders.page', 2);
    }

    public function test_personal_performance_supports_single_dates_business_timezone_and_missing_rates(): void
    {
        $this->order(['order_time' => '2026-08-01T15:59:59Z', 'amount_usd' => 10]);
        $this->order(['order_time' => '2026-08-01T16:00:00Z', 'amount_usd' => 20]);
        $this->order(['order_time' => '2026-08-02T16:00:00Z', 'amount_usd' => 30]);
        $this->order(['order_time' => '2026-08-02T00:00:00Z', 'currency' => 'EUR', 'amount_usd' => null]);
        $url = '/api/workbench/sa-sales/personal/report?staffCode=AA&startDate=2026-08-02&endDate=2026-08-02';
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.daily')
            ->assertJsonPath('data.summary.sales', 20)->assertJsonPath('data.summary.orders', 1)
            ->assertJsonPath('data.summary.missingRates', 1)->assertJsonPath('data.orders.total', 2);
        $this->getJson($url . '&scope=invoice')->assertOk()->assertJsonPath('data.orders.total', 0)
            ->assertJsonPath('data.summary.refundRateOrders', 0)->assertJsonPath('data.summary.refundRateAmount', 0);
        $this->getJson('/api/workbench/sa-sales/personal/report?staffCode=AA&startDate=2026-07-01&endDate=2026-07-31')
            ->assertOk()->assertJsonCount(31, 'data.daily')->assertJsonPath('data.orders.total', 0);
    }

    public function test_personal_commission_combines_sources_without_changing_sa_rankings(): void
    {
        $this->order(['amount_usd' => 80000]);
        $invoice = \App\Models\InvoiceOrder::create(['order_number' => 'COMMISSION-I', 'invoice_date' => '2026-08-02', 'order_date' => '2026-08-02', 'invoice_status' => 'Paid', 'amount_usd' => 80000]);
        \App\Models\InvoiceStaffAllocation::create(['invoice_id' => $invoice->id, 'staff_code' => 'AA', 'share_ratio' => 1]);
        $query = '?startDate=2026-08-01&endDate=2026-08-31';
        $sa = $this->getJson('/api/workbench/sa-sales/report' . $query)->assertOk()->json('data');
        $personal = $this->getJson('/api/workbench/sa-sales/personal/report' . $query . '&staffCode=AA')
            ->assertOk()->assertJsonPath('data.summary.commissionUsd', 3900)->json('data.summary');
        $this->assertEquals(160000, $personal['netSales']);
        $this->assertEquals(3000, $sa['employees'][0]['commission'] + $sa['invoiceSales']['employees'][0]['commission']);
        $this->getJson('/api/workbench/sa-sales/personal/report' . $query . '&staffCode=AA&scope=order')
            ->assertOk()->assertJsonPath('data.summary.commissionUsd', 1500)->assertJsonPath('data.orders.total', 1);
    }

    public function test_personal_options_names_validation_and_permissions(): void
    {
        $this->admin->update(['staff_code' => 'AA', 'display_name' => 'Alice']);
        $this->order(['staff_code' => 'BB']);
        $this->getJson('/api/workbench/sa-sales/personal/options')->assertOk()
            ->assertJsonPath('data.defaultStaffCode', 'AA')->assertJsonFragment(['code' => 'AA', 'name' => 'Alice'])
            ->assertJsonFragment(['code' => 'BB', 'name' => 'BB']);
        $url = '/api/workbench/sa-sales/personal/report';
        foreach (['', '?staffCode=AA&startDate=2026-08-03&endDate=2026-08-01',
            '?staffCode=AA&startDate=2025-01-01&endDate=2026-01-02',
            '?staffCode=AA&startDate=2026-08-01&endDate=2026-08-31&scope=invalid',
            '?staffCode=AA&startDate=2026-08-01&endDate=2026-08-31&per_page=101'] as $query) {
            $this->getJson($url . $query)->assertUnprocessable();
        }
        $query = '?staffCode=AA&startDate=2026-08-01&endDate=2026-08-31';
        $this->loginWith(['business.sa_sales.list']);
        $this->getJson($url . $query)->assertForbidden();
        $this->getJson('/api/workbench/sa-sales/personal/options')->assertForbidden();
        $this->loginWith(['business.sa_sales.personal']);
        $this->getJson($url . $query)->assertForbidden();
        $this->loginWith(['business.sa_sales.list', 'business.sa_sales.personal']);
        $this->getJson($url . $query)->assertOk();
        $this->getJson('/api/workbench/sa-sales/personal/options')->assertOk();
        $this->withHeader('Authorization', '');
        $this->getJson($url . $query)->assertUnauthorized();
        $this->getJson('/api/workbench/sa-sales/personal/options')->assertUnauthorized();
    }

    public function test_invoice_list_omits_raw_database_snapshot_but_detail_keeps_it(): void
    {
        $invoice = \App\Models\InvoiceOrder::create([
            'order_number' => 'LIGHT-LIST', 'invoice_date' => '2026-09-01', 'order_date' => '2026-09-02',
            'invoice_status' => 'Paid', 'amount_usd' => 125.50,
            'raw' => ['screenshot' => str_repeat('image-data', 10000), 'note' => 'Original OCR'],
        ]);
        \App\Models\InvoiceItem::create(['invoice_id' => $invoice->id, 'product_name' => 'Shirt', 'quantity' => 2, 'price' => 60, 'notes' => 'Keep list fields']);
        $dao = app(\App\Dao\InvoiceDao::class);
        $service = app(\App\Services\InvoiceService::class);
        $listInvoice = $dao->listing([])->items()[0];
        $detail = $dao->find($invoice->id);
        $this->assertArrayNotHasKey('raw', $listInvoice->getAttributes());
        $this->assertSame('Original OCR', $detail->raw['note']);
        $this->assertEquals($service->present($detail), $service->present($listInvoice));
    }

    public function test_scoped_paypal_activity_uses_global_cutoff_and_reads_only_selected_accounts(): void
    {
        $this->order(['order_time' => '2026-08-01T02:00:00Z', 'receiving_paypal' => ' SELECTED@example.test ', 'amount_usd' => 10]);
        $this->order(['order_time' => '2026-08-03T02:00:00Z', 'receiving_paypal' => 'another@example.test', 'amount_usd' => 20]);
        foreach (['2026-08-02', '2026-08-04'] as $date) {
            $legacy = ['clientOrderId' => 'LEGACY-' . $date, 'recipientPaypal' => 'selected@example.test', 'paymentStatus' => 'completed', 'amount' => 30, 'currency' => 'USD', 'createTime' => $date . 'T12:00:00Z'];
            \App\Models\LegacyDashboardDay::create(['day' => $date, 'source_sha256' => str_repeat('a', 64), 'source_size_bytes' => 1,
                'snapshot_cutoff_asia_shanghai' => $date, 'payload' => ['orders' => [$legacy, $legacy]]]);
        }
        $service = app(\App\Services\PaypalActivityService::class);
        $all = $service->groups();
        $selected = $service->groups(['selected@example.test']);
        $this->assertSame(['selected@example.test' => $all['selected@example.test']], $selected);
        $this->assertSame(40.0, $selected['selected@example.test']['amount']);
        $this->assertCount(2, $selected['selected@example.test']['orders']);
        $this->assertSame([], $service->groups([]));
        $this->assertSame([], $service->groups(['missing@example.test']));
        $this->assertCount(1, collect(app(\App\Dao\PaypalActivityDao::class)->orders(['selected@example.test'])));
    }

    public function test_scoped_paypal_queries_preserve_legacy_duplicate_winners_and_equal_time_order(): void
    {
        foreach ([10, 25] as $amount) {
            $this->order(['client_order_id' => 'REUSED', 'receiving_paypal' => 'duplicate@example.test', 'amount_usd' => $amount]);
        }
        // 同时刻的不同订单同样保留，并使用稳定的明细排序。
        $this->order(['client_order_id' => 'SEPARATE', 'receiving_paypal' => 'duplicate@example.test', 'amount_usd' => 50]);
        $service = app(\App\Services\PaypalActivityService::class);
        $expected = $service->groups()['duplicate@example.test'];
        $this->assertSame($expected, $service->groups(['duplicate@example.test'])['duplicate@example.test']);
        $this->assertCount(2, $expected['orders']);
    }

    public function test_paypal_log_pages_match_exports_and_restore_account_names(): void
    {
        $account = \App\Models\PaypalAccount::create([
            'email' => 'logs@example.test', 'account_name' => 'Current Account', 'active' => true, 'version' => 1,
        ]);
        $former = User::create(['username' => 'former-paypal-operator', 'display_name' => '', 'password_hash' => Hash::make('Test-123'), 'active' => 0]);
        $former->delete();
        for ($index = 1; $index <= 21; $index++) {
            BusinessOperationLog::create([
                'module' => 'paypal', 'entity_id' => (string) $account->id, 'action' => 'balance',
                'actor_user_id' => $index === 21 ? $former->id : $this->admin->id,
                'before' => ['balance' => 100], 'after' => ['accountName' => ' ', 'balance' => 99.9],
                'created_at' => '2026-09-09 00:00:' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            ]);
        }
        // 历史日志只有邮箱、空白名称或保留旧名称；缺失财务快照不能用当前余额补写。
        \App\Models\SystemState::create(['key' => 'paypal_legacy_state', 'value' => ['changeLogs' => [
            ['createdAt' => '2026-09-09T01:00:00Z', 'email' => ' LOGS@EXAMPLE.TEST ', 'field' => 'Current Balance', 'action' => 'Withdrawal', 'previousValue' => 10, 'newValue' => 0, 'delta' => -10, 'updatedBy' => 'Legacy Operator'],
            ['createdAt' => '2026-09-09T00:00:22Z', 'email' => 'logs@example.test', 'accountName' => 'Legacy, Recorded', 'field' => 'Number of Reviews', 'action' => 'review', 'previousValue' => 0, 'newValue' => 1, 'delta' => 1],
            ['createdAt' => '2026-09-09T00:00:22Z', 'email' => 'logs@example.test', 'accountName' => ' ', 'action' => 'Baseline Repair'],
            ['email' => 'unknown@example.test', 'accountName' => 'Archived Account', 'action' => 'custom_account'],
        ]]]);
        // 已停用/软删除的账户仍应保留日志中的名称，也不能将其他模块日志混入。
        $account->update(['active' => false]);
        $account->delete();
        BusinessOperationLog::create(['module' => 'invoice', 'entity_id' => '100', 'action' => 'create', 'actor_user_id' => $this->admin->id]);

        $fields = ['time', 'field', 'action', 'accountName', 'email', 'previous', 'current', 'delta', 'actor'];
        foreach (['zh-CN', 'en-US'] as $locale) {
            $first = $this->getJson('/api/workbench/paypal/logs?locale=' . $locale)->assertOk()
                ->assertJsonCount(20, 'data.list')->assertJsonPath('data.total', 25)->assertJsonPath('data.per_page', 20)->json('data.list');
            $second = $this->getJson('/api/workbench/paypal/logs?page=2&locale=' . $locale)->assertOk()
                ->assertJsonCount(5, 'data.list')->json('data.list');
            $rows = array_merge($first, $second);
            $this->assertCount(25, array_unique(array_column($rows, 'id')));
            $this->assertSame('2026-09-09 09:00:00', $rows[0]['time']);
            $this->assertSame('Current Account', $rows[0]['accountName']);
            $this->assertSame('Legacy Operator', $rows[0]['actor']);
            $this->assertSame('0.00', $rows[0]['current']);
            $this->assertSame('-10.00', $rows[0]['delta']);
            $this->assertSame($locale === 'en-US' ? 'Withdrawal' : '提款', $rows[0]['action']);
            $byId = collect($rows)->keyBy('id');
            $this->assertSame('Legacy, Recorded', $byId['legacy:2']['accountName']);
            $this->assertSame('1', $byId['legacy:2']['current']);
            $this->assertSame('Current Account', $byId['legacy:3']['accountName']);
            $this->assertSame('', $byId['legacy:3']['previous']);
            $this->assertSame('', $byId['legacy:3']['delta']);
            $this->assertSame('legacy:4', $rows[24]['id']);
            $local = array_values(array_filter($rows, fn ($row) => str_starts_with($row['id'], 'local:')));
            $this->assertSame('former-paypal-operator', $local[0]['actor']);
            $this->assertSame('Admin', $local[1]['actor']);
            $this->assertSame('Current Account', $local[0]['accountName']);
            $this->assertSame('logs@example.test', $local[0]['email']);
            $this->assertSame('99.90', $local[0]['current']);

            $csv = $this->get('/api/workbench/paypal/logs/export?page=2&per_page=20&locale=' . $locale)->assertOk()->streamedContent();
            $lines = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\r\n", rtrim(substr($csv, 3), "\r\n")));
            $headers = array_shift($lines);
            $this->assertSame($locale === 'en-US' ? 'Account Name' : '账号名', $headers[3]);
            $this->assertCount(25, $lines);
            $this->assertSame(array_map(fn ($row) => array_map(fn ($key) => $row[$key], $fields), $rows), $lines);
        }
        $this->getJson('/api/workbench/paypal/logs?per_page=50')->assertOk()->assertJsonCount(25, 'data.list');
        $this->getJson('/api/workbench/paypal/logs?page=0')->assertUnprocessable();
        $this->getJson('/api/workbench/paypal/logs?per_page=101')->assertUnprocessable();
        $this->getJson('/api/workbench/paypal/logs?locale=invalid')->assertUnprocessable();
    }

    public function test_paypal_withdrawal_business_dates_match_filters_charts_and_exports(): void
    {
        $account = \App\Models\PaypalAccount::create(['email' => 'date@example.test', 'active' => true, 'version' => 1, 'meta' => []]);
        // 同一笔历史流水同时存在于旧共享状态和流水表，不能因修复日期而重复计算。
        \App\Models\PaypalWithdrawal::create(['account_id' => $account->id, 'amount' => 10, 'withdrawn_at' => '2026-09-07']);
        \App\Models\SystemState::create(['key' => 'paypal_legacy_state', 'value' => [
            'balances' => ['date@example.test' => ['base' => 100, 'baselineReceived' => 0, 'updatedAt' => '2026-08-01T00:00:00Z']],
            'withdrawals' => ['date@example.test' => ['entries' => [
                ['amount' => 10, 'date' => '2026-09-07', 'createdAt' => '2026-09-06T23:04:49.562Z'],
                ['amount' => 20, 'createdAt' => '2026-09-06T23:07:54.843Z'],
                ['amount' => 30, 'date' => '2026-09-05', 'createdAt' => '2026-09-06T23:13:04.522Z'],
            ]]],
        ]]);
        app(\App\Services\PaypalLegacyService::class)->restore([
            'totalWithdrewImportedAt' => '2026-07-01 07:45:14',
            'rows' => [['email' => 'date@example.test', 'accountName' => 'Date test', 'importedTotalWithdrew' => 0]],
        ], true);

        $query = 'startDate=2026-09-07&endDate=2026-09-07';
        $this->getJson('/api/workbench/paypal/withdrawals?' . $query)->assertOk()
            ->assertJsonCount(2, 'data.list')->assertJsonPath('data.amount', 30)
            ->assertJsonPath('data.list.0.date', '2026-09-07');
        $this->getJson('/api/workbench/paypal/withdrawals?startDate=2026-09-06&endDate=2026-09-06')
            ->assertOk()->assertJsonCount(0, 'data.list');
        $this->getJson('/api/workbench/paypal/statistics?mode=daily&' . $query)
            ->assertOk()->assertJsonPath('data.0.period', '2026-09-07')->assertJsonPath('data.0.amount', 30);
        $csv = $this->get('/api/workbench/paypal/withdrawals/export?locale=en-US&' . $query)->assertOk()->streamedContent();
        $this->assertSame(2, substr_count($csv, '2026-09-07'));
        $this->assertStringNotContainsString('2026-09-06', $csv);
        $this->getJson('/api/workbench/paypal/withdrawals')->assertOk()->assertJsonPath('data.count', 3)->assertJsonPath('data.amount', 60);
        $this->getJson('/api/workbench/paypal')->assertOk()->assertJsonPath('data.list.0.balance', 40)->assertJsonPath('data.list.0.withdrawn', 60);
    }

    public function test_warehouse_paging_and_procurement_available_filter_precede_slicing(): void
    {
        for ($index = 1; $index <= 25; $index++) {
            $order = $this->order(['order_id' => 'WAREHOUSE-' . $index]);
            $task = \App\Models\ProcurementTask::create(['order_id' => $order->order_id, 'purchase_status' => 'warehouse_arrived',
                'legacy_id' => 'page-task-' . $index, 'raw' => ['sourceKey' => 'order:' . $order->id, 'date' => '2026-08-01']]);
            \App\Models\WarehouseRecord::create(['procurement_task_id' => $task->id, 'fulfillment_status' => 'pending_inspection', 'items' => [], 'history' => []]);
        }
        $this->order(['order_id' => 'AVAILABLE-ORDER']);
        $first = $this->getJson('/api/workbench/warehouse?scope=all')->assertOk()->assertJsonCount(20, 'data.list')
            ->assertJsonPath('data.allTotal', 25)->json('data');
        $second = $this->getJson('/api/workbench/warehouse?scope=all&page=2')->assertOk()->assertJsonCount(5, 'data.list')
            ->assertJsonPath('data.allTotal', 25)->json('data');
        $this->assertSame([], array_intersect(array_column($first['list'], 'id'), array_column($second['list'], 'id')));
        $this->getJson('/api/workbench/warehouse?scope=all&per_page=50')->assertOk()->assertJsonCount(25, 'data.list');
        $this->getJson('/api/workbench/procurement?available_only=1')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.orderId', 'AVAILABLE-ORDER');
    }

    public function test_lists_page_on_server_and_keep_full_summaries(): void
    {
        for ($index = 1; $index <= 25; $index++) {
            $this->order(['order_id' => 'PAGE-' . $index, 'client_order_id' => 'CLIENT-' . $index,
                'receiving_paypal' => 'paged@example.test', 'amount_original' => 12.34, 'amount_usd' => 12.34]);
            \App\Models\InvoiceOrder::create(['order_number' => 'PAGE-INV-' . $index, 'invoice_date' => '2026-08-01',
                'invoice_status' => 'Paid', 'amount_usd' => 12.34]);
            \App\Models\PaypalAccount::create(['email' => 'page' . $index . '@example.test', 'account_name' => 'Paged ' . $index, 'active' => true, 'version' => 1]);
            \App\Models\InfluencerDomain::create(['domain' => 'page' . $index . '.test', 'influencer_name' => 'Creator ' . $index, 'confirmed' => true]);
            BusinessOperationLog::create(['module' => 'invoice', 'entity_id' => (string) $index, 'action' => 'create', 'actor_user_id' => $this->admin->id]);
        }
        foreach (['invoices', 'paypal', 'influencers/directory'] as $path) {
            $first = $this->getJson('/api/workbench/' . $path)->assertOk()->assertJsonCount(20, 'data.list')
                ->assertJsonPath('data.total', 25)->assertJsonPath('data.per_page', 20)->json('data');
            $second = $this->getJson('/api/workbench/' . $path . '?page=2')->assertOk()->assertJsonCount(5, 'data.list')->json('data');
            $key = $path === 'influencers/directory' ? 'name' : 'id';
            $this->assertSame([], array_intersect(array_column($first['list'], $key), array_column($second['list'], $key)));
            $this->getJson('/api/workbench/' . $path . '?per_page=50')->assertOk()->assertJsonCount(25, 'data.list');
            $this->getJson('/api/workbench/' . $path . '?per_page=101')->assertUnprocessable();
            $this->getJson('/api/workbench/' . $path . '?page=0')->assertUnprocessable();
        }
        $this->getJson('/api/workbench/invoices/logs')->assertOk()->assertJsonCount(20, 'data.data')->assertJsonPath('data.total', 25);
        $this->getJson('/api/workbench/invoices/logs?page=2')->assertOk()->assertJsonCount(5, 'data.data');
        $this->getJson('/api/workbench/paypal/orders?email=paged@example.test')->assertOk()->assertJsonCount(20, 'data.list')->assertJsonPath('data.total', 25);
        $this->getJson('/api/workbench/paypal/orders?email=paged@example.test&page=2')->assertOk()->assertJsonCount(5, 'data.list');
        $this->getJson('/api/workbench/influencers/report?month=2026-08')->assertOk()->assertJsonCount(20, 'data.list')
            ->assertJsonCount(25, 'data.chart')->assertJsonPath('data.total', 25);
        $this->getJson('/api/workbench/sa-sales/report?startDate=2026-08-01&endDate=2026-08-31&includeDetails=1')
            ->assertOk()->assertJsonCount(0, 'data.detail')->assertJsonCount(0, 'data.invoiceSales.detail');
        $this->getJson('/api/workbench/sa-sales/orders')->assertOk()->assertJsonCount(20, 'data.list')->assertJsonPath('data.total', 25);
        $this->getJson('/api/workbench/sa-sales/orders?page=2')->assertOk()->assertJsonCount(5, 'data.list')->assertJsonPath('data.totalAmount', 308.5);
        $summary = $this->getJson('/api/workbench/paypal')->assertOk()->json('data.summary');
        $this->assertSame($summary, $this->getJson('/api/workbench/paypal?page=2')->assertOk()->json('data.summary'));
        $this->getJson('/api/workbench/influencers/directory?keyword=Creator%2025')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.summary.influencers', 25);
        $this->getJson('/api/workbench/procurement')->assertOk()->assertJsonCount(20, 'data.list')->assertJsonPath('data.total', 50);
        $this->getJson('/api/workbench/procurement?page=2&per_page=50')->assertOk()->assertJsonPath('data.page', 1)->assertJsonCount(50, 'data.list');
    }

    public function test_invoice_log_list_matches_exports_and_resolves_operator_names(): void
    {
        $deleted = BusinessOperationLog::create([
            'module' => 'invoice', 'entity_id' => '975', 'action' => 'delete', 'actor_user_id' => $this->admin->id,
            'before' => ['order_number' => 'INV-975', 'customer_full_name' => 'Example Buyer'],
            'created_at' => '2026-09-09T08:01:35Z',
        ]);
        $legacy = BusinessOperationLog::create([
            'module' => 'invoice', 'entity_id' => '976', 'action' => 'update', 'actor_user_id' => $this->admin->id,
            'after' => ['legacyInvoiceOperationLog' => [
                'operationTime' => '2026-09-08T23:05:00Z', 'operator' => 'Legacy Operator', 'action' => 'update',
                'orderNumber' => 'INV-976', 'customerFullName' => 'Legacy Buyer', 'changedFields' => ['Amount', 'Status'],
                'details' => 'Amount: 10 -> 20', 'recordId' => 'legacy-976',
            ]],
        ]);
        $ocr = BusinessOperationLog::create([
            'module' => 'invoice', 'entity_id' => '156225', 'action' => 'ocr', 'actor_user_id' => $this->admin->id,
            'after' => ['blocks' => 12, 'engine' => 'example', 'legacyInvoiceOperationLog' => ['operator' => '']],
        ]);
        $former = User::create(['username' => 'former-operator', 'display_name' => '', 'password_hash' => Hash::make('Test-123'), 'active' => 0]);
        $former->delete();
        $formerLog = BusinessOperationLog::create(['module' => 'invoice', 'entity_id' => '977', 'action' => 'create', 'actor_user_id' => $former->id]);
        $fields = ['operationTime', 'operator', 'actionLabel', 'orderNumber', 'customer', 'changedFields', 'details', 'recordId'];

        foreach (['zh-CN', 'en-US'] as $locale) {
            $rows = $this->getJson('/api/workbench/invoices/logs?locale=' . $locale)->assertOk()
                ->assertJsonCount(4, 'data.data')->assertJsonPath('data.per_page', 20)->json('data.data');
            $byId = collect($rows)->keyBy('id');
            $this->assertSame('Admin', $byId[$deleted->id]['operator']);
            $this->assertSame('2026-09-09 16:01:35', $byId[$deleted->id]['operationTime']);
            $this->assertSame('INV-975', $byId[$deleted->id]['orderNumber']);
            $this->assertSame('Example Buyer', $byId[$deleted->id]['customer']);
            $this->assertSame('delete', $byId[$deleted->id]['action']);
            $this->assertSame('Legacy Operator', $byId[$legacy->id]['operator']);
            $this->assertSame('2026-09-09 07:05:00', $byId[$legacy->id]['operationTime']);
            $this->assertSame('Amount | Status', $byId[$legacy->id]['changedFields']);
            $this->assertSame('legacy-976', $byId[$legacy->id]['recordId']);
            $this->assertSame('Admin', $byId[$ocr->id]['operator']);
            $this->assertSame('', $byId[$ocr->id]['orderNumber']);
            $this->assertSame('156225', $byId[$ocr->id]['recordId']);
            $this->assertSame($locale === 'en-US' ? 'Invoice recognition' : 'Invoice 截图识别', $byId[$ocr->id]['actionLabel']);
            $this->assertSame('former-operator', $byId[$formerLog->id]['operator']);

            $csv = $this->get('/api/workbench/invoices/logs/export?locale=' . $locale . '&page=2&per_page=1')->assertOk()->streamedContent();
            $lines = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\r\n", rtrim(substr($csv, 3), "\r\n")));
            $this->assertSame($locale === 'en-US'
                ? ['Operation Time', 'Operator', 'Action', 'Order Number', 'Customer', 'Changed Fields', 'Details', 'Record ID']
                : ['操作时间', '操作人', '操作', '订单号', '客户', '变更字段', '详情', '记录ID'], array_shift($lines));
            $this->assertSame(array_map(fn ($row) => array_map(fn ($field) => $row[$field], $fields), $rows), $lines);
        }

        $first = $this->getJson('/api/workbench/invoices/logs?per_page=2')->assertOk()->assertJsonCount(2, 'data.data')->json('data.data');
        $second = $this->getJson('/api/workbench/invoices/logs?per_page=2&page=2')->assertOk()->assertJsonCount(2, 'data.data')->json('data.data');
        $this->assertSame([], array_intersect(array_column($first, 'id'), array_column($second, 'id')));
    }

    public function test_invoice_logs_keep_separate_read_and_export_permissions(): void
    {
        $this->loginWith(['business.invoice.list']);
        $this->getJson('/api/workbench/invoices/logs')->assertForbidden();
        $this->getJson('/api/workbench/invoices/logs/export')->assertForbidden();
        $this->loginWith(['business.invoice.logs']);
        $this->getJson('/api/workbench/invoices/logs')->assertOk();
        $this->getJson('/api/workbench/invoices/logs/export')->assertForbidden();
        $this->getJson('/api/workbench/invoices/logs?locale=invalid')->assertUnprocessable();
        $this->getJson('/api/workbench/invoices/logs?page=0')->assertUnprocessable();
        $this->loginWith(['business.invoice.logs', 'business.invoice.export']);
        $this->getJson('/api/workbench/invoices/logs/export?locale=en-US')->assertOk();
    }

    public function test_procurement_log_list_and_exports_resolve_the_same_operator_names(): void
    {
        $account = User::create(['username' => 'purchaser-login', 'display_name' => '  ', 'password_hash' => Hash::make('Test-123'), 'active' => 1]);
        $former = User::create(['username' => 'former-purchaser', 'display_name' => '历史采购员', 'password_hash' => Hash::make('Test-123'), 'active' => 0]);
        $former->delete();
        $expected = [];
        foreach ([[$this->admin->id, 'Admin'], [$account->id, 'purchaser-login'], [$former->id, '历史采购员'], [999999, '#999999']] as $index => [$actor, $name]) {
            $log = BusinessOperationLog::create(['module' => 'procurement', 'entity_id' => 'purchase-' . $index, 'action' => 'update', 'actor_user_id' => $actor]);
            $expected[$log->entity_id] = $name;
        }
        BusinessOperationLog::create(['module' => 'warehouse', 'entity_id' => 'warehouse-1', 'action' => 'update', 'actor_user_id' => $former->id]);
        BusinessOperationLog::create(['module' => 'invoice', 'entity_id' => 'unrelated-invoice', 'action' => 'update', 'actor_user_id' => $this->admin->id]);

        $rows = $this->getJson('/api/workbench/procurement/logs')->assertOk()
            ->assertJsonCount(4, 'data.data')->assertJsonPath('data.total', 4)->assertJsonPath('data.per_page', 20)->json('data.data');
        foreach ($rows as $row) {
            $this->assertSame($expected[$row['entity_id']], $row['operator']);
            $this->assertArrayHasKey('actor_user_id', $row);
        }
        $first = $this->getJson('/api/workbench/procurement/logs?per_page=2')->assertOk()->assertJsonCount(2, 'data.data')->json('data.data');
        $second = $this->getJson('/api/workbench/procurement/logs?per_page=2&page=2')->assertOk()->assertJsonCount(2, 'data.data')->json('data.data');
        $this->assertSame([], array_intersect(array_column($first, 'id'), array_column($second, 'id')));

        foreach (['zh-CN', 'en-US'] as $locale) {
            $csv = $this->get('/api/workbench/procurement/logs/export?locale=' . $locale . '&page=2&per_page=1')->assertOk()->streamedContent();
            $lines = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), explode("\r\n", rtrim(substr($csv, 3), "\r\n")));
            $this->assertSame($locale === 'en-US'
                ? ['Operation Time', 'Action', 'Entity', 'Record ID', 'Operator']
                : ['操作时间', '操作', '对象', '记录ID', '操作人'], array_shift($lines));
            $this->assertCount(5, $lines);
            $exported = array_column($lines, 4, 3);
            foreach ($expected as $entityId => $operator) {
                $this->assertSame($operator, $exported[$entityId]);
            }
            $this->assertSame('历史采购员', $exported['warehouse-1']);
            $this->assertArrayNotHasKey('unrelated-invoice', $exported);
        }
    }

    public function test_procurement_log_names_require_log_and_export_permissions(): void
    {
        $this->loginWith(['business.procurement.list']);
        $this->getJson('/api/workbench/procurement/logs')->assertForbidden();
        $this->getJson('/api/workbench/procurement/logs/export')->assertForbidden();
        $this->loginWith(['business.procurement.logs']);
        $this->getJson('/api/workbench/procurement/logs')->assertOk();
        $this->getJson('/api/workbench/procurement/logs/export')->assertForbidden();
        $this->getJson('/api/workbench/procurement/logs?page=0')->assertUnprocessable();
        $this->getJson('/api/workbench/procurement/logs?per_page=101')->assertUnprocessable();
        $this->loginWith(['business.procurement.export']);
        $this->getJson('/api/workbench/procurement/logs/export')->assertForbidden();
        $this->loginWith(['business.procurement.logs', 'business.procurement.export']);
        $this->getJson('/api/workbench/procurement/logs')->assertOk();
        $this->getJson('/api/workbench/procurement/logs/export')->assertOk();
    }

    public function test_exports_restore_source_columns_languages_and_all_filtered_rows(): void
    {
        for ($index = 1; $index <= 25; $index++) {
            $this->order(['order_id' => 'EXPORT-' . $index, 'client_order_id' => 'CLIENT-' . $index,
                'paypal_order_id' => 'PP-' . $index, 'customer_name' => '=SUM(1,2)', 'receiving_paypal' => 'export@example.test',
                'amount_original' => 12.34, 'currency' => 'EUR', 'amount_usd' => 15.42]);
        }
        $csv = $this->get('/api/workbench/paypal/orders/export?email=export@example.test&locale=en-US&page=2&per_page=1')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = explode("\r\n", substr($csv, 3));
        $this->assertSame(['Order Time', 'Order ID', 'PayPal Order ID', 'Customer Full Name', 'Source Site', 'Classification', 'Order Status', 'Amount', 'Currency'], str_getcsv($lines[0], ',', '"', ''));
        $this->assertCount(27, $lines);
        $this->assertStringContainsString('PP-25', $csv);
        $this->assertStringContainsString('12.34,EUR', $csv);
        $this->assertStringContainsString("'=SUM(1,2)", $csv);
        $chinese = $this->get('/api/workbench/paypal/orders/export?email=export@example.test&locale=zh-CN')->assertOk()->streamedContent();
        $this->assertSame(['下单时间', '订单ID', 'PayPal订单ID', '顾客全名', '来源网站', '归类', '订单状态', '金额', '币种'], str_getcsv(explode("\r\n", substr($chinese, 3))[0], ',', '"', ''));
        $procurement = $this->get('/api/workbench/procurement/export?locale=en-US&per_page=1')->assertOk()->streamedContent();
        $this->assertSame(['Order Time', 'Order ID', 'PayPal Order ID', 'Customer', 'Source Site', 'Amount', 'Currency', 'Product', 'Supplier', 'Purchaser', 'Cost', 'Expected Arrival', 'Carrier', 'Tracking', 'Tracking Phone', 'Delivery Status', 'Purchase Status', 'Priority', 'Notes'], str_getcsv(explode("\r\n", substr($procurement, 3))[0], ',', '"', ''));
        $this->assertCount(27, explode("\r\n", $procurement));
        $sa = $this->get('/api/workbench/sa-sales/export?startDate=2026-08-01&endDate=2026-08-31&locale=en-US')->assertOk()->streamedContent();
        foreach (['SA Sales Performance Report', 'Summary', 'Employee Ranking', 'Invoice Employee Ranking', 'Channel Summary', 'Daily Summary', 'Order Detail', 'client:CLIENT-25'] as $section) {
            $this->assertStringContainsString($section, $sa);
        }
        $logs = $this->get('/api/workbench/invoices/logs/export?locale=en-US')->assertOk()->streamedContent();
        $this->assertSame(['Operation Time', 'Operator', 'Action', 'Order Number', 'Customer', 'Changed Fields', 'Details', 'Record ID'], str_getcsv(explode("\r\n", substr($logs, 3))[0], ',', '"', ''));
        $this->getJson('/api/workbench/paypal/orders/export?email=export@example.test&locale=invalid')->assertUnprocessable();
        $this->loginWith(['business.paypal.withdrawals']);
        $this->get('/api/workbench/paypal/withdrawals/export')->assertForbidden();
        $this->loginWith(['business.paypal.withdrawals', 'business.paypal.export']);
        $this->get('/api/workbench/paypal/withdrawals/export?locale=en-US')->assertOk();
    }

    public function test_operations_directory_restores_departments_search_and_safe_links(): void
    {
        $attributes = ['source_key' => 'purchasing-example', 'department' => 'purchasing', 'provider' => 'Sheets',
            'title_zh' => '采购跟进', 'title_en' => 'Purchasing Follow-up', 'description_zh' => '采购进度',
            'description_en' => 'Supplier progress', 'url' => 'https://example.test/sheet', 'active' => true, 'sort' => 10];
        \App\Models\OnlineSpreadsheet::create($attributes);
        \App\Models\OnlineSpreadsheet::create(array_merge($attributes, ['source_key' => 'unsafe-link', 'url' => 'javascript:alert(1)']));
        \App\Models\OnlineSpreadsheet::create(array_merge($attributes, ['source_key' => 'disabled-link', 'active' => false]));
        \App\Models\OnlineSpreadsheet::create(array_merge($attributes, ['source_key' => 'deleted-link', 'deleted_at' => now()]));
        $report = $this->getJson('/api/workbench/operations/directory')->assertOk()->assertJsonPath('data.total', 6)
            ->assertJsonCount(6, 'data.departments')->assertJsonPath('data.departments.0.count', 5)
            ->assertJsonPath('data.departments.1.count', 1)->assertJsonPath('data.departments.5.count', 0)->json('data');
        $this->assertSame(['customer-service', 'purchasing', 'warehouse', 'operations', 'influencer', 'finance'], array_column($report['departments'], 'code'));
        $this->getJson('/api/workbench/operations/directory?keyword=WPS')->assertOk()->assertJsonPath('data.matched', 5)->assertJsonPath('data.total', 6);
        $this->getJson('/api/workbench/operations/directory?keyword=' . urlencode('采购部'))->assertOk()->assertJsonPath('data.matched', 1);
        $this->getJson('/api/workbench/operations/directory?department=finance')->assertOk()->assertJsonPath('data.matched', 0)->assertJsonCount(0, 'data.rows');
        $this->getJson('/api/workbench/operations?keyword=SUPPLIER')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.url', 'https://example.test/sheet');
        $this->getJson('/api/workbench/operations/directory?keyword=' . str_repeat('x', 256))->assertUnprocessable();
    }

    public function test_operations_directory_requires_its_own_permission(): void
    {
        $this->loginWith(['business.paypal.list']);
        $this->getJson('/api/workbench/operations/directory')->assertForbidden();
        $this->getJson('/api/workbench/operations')->assertForbidden();
        $this->loginWith(['business.operations.list']);
        $this->getJson('/api/workbench/operations/directory')->assertOk()->assertJsonPath('data.total', 5);
        $this->getJson('/api/workbench/paypal')->assertForbidden();
    }

    private string $schema;

    private string $files;

    private User $admin;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RBAC_TEST_POSTGRES') !== '1') {
            $this->markTestSkipped('Use phpunit-workbench.xml with PostgreSQL.');
        }
        $this->schema = 'rbac_test_' . bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA ' . $this->schema);
        DB::statement('SET search_path TO ' . $this->schema);
        (require database_path('migrations/2026_09_04_180000_create_rbac.php'))->up();
        BusinessSchema::create(['orders','invoice_orders','invoice_items','invoice_staff_allocations','exchange_rates','order_user_overrides','order_staff_allocations','procurement_tasks','procurement_removed_orders','warehouse_records','online_spreadsheets','business_operation_logs','attachments','paypal_accounts','paypal_balance_entries','paypal_reviews','paypal_withdrawals','influencers','influencer_domains','system_state','legacy_dashboard_days']);
        (require database_path('migrations/2026_09_06_140001_add_workbench_permissions.php'))->up();
        (require database_path('migrations/2026_09_08_140000_add_procurement_logistics_permission.php'))->up();
        (require database_path('migrations/2026_09_08_180000_add_paypal_monitor_permissions.php'))->up();
        (require database_path('migrations/2026_09_11_180000_add_sa_personal_performance_permission.php'))->up();
        $this->admin = User::create(['username' => 'admin','display_name' => 'Admin','password_hash' => Hash::make('Test-123'),'active' => 1,'role_id' => 1]);
        $this->adminToken = ApiToken::issue($this->admin->id)['plain'];
        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken);
        $this->files = storage_path('framework/testing/' . $this->schema);
        mkdir($this->files, 0775, true);
        config(['business.attachments_root' => $this->files]);
        config(['influencer' => ['domains' => [], 'tiers' => []]]);
    }

    protected function tearDown(): void
    {
        if (isset($this->schema) && preg_match('/^rbac_test_[a-f0-9]{16}$/', $this->schema)) {
            DB::statement('SET search_path TO public');
            DB::statement('DROP SCHEMA ' . $this->schema . ' CASCADE');
            if (isset($this->files) && $this->files === storage_path('framework/testing/' . $this->schema)) {
                File::deleteDirectory($this->files);
            }
        }
        parent::tearDown();
    }

    public function test_influencer_report_respects_month_status_and_zero_sales_directory(): void
    {
        config(['influencer' => ['domains' => ['ann.test' => 'Ann', 'zero.test' => 'Zero'], 'tiers' => ['Ann' => 'top', 'Zero' => 'mid', 'NoWebsite' => 'mid']]]);
        $this->order(['classification' => 'top_influencer', 'influencer_name' => 'Ann', 'amount_usd' => 0.1]);
        $this->order(['classification' => 'mid_influencer', 'influencer_name' => 'Ann', 'amount_usd' => 0.2, 'order_status' => 'paid']);
        $this->order(['classification' => 'top_influencer', 'influencer_name' => 'Ann', 'amount_usd' => 900, 'order_status' => 'pending']);
        $this->order(['classification' => 'top_influencer', 'influencer_name' => 'Ann', 'amount_usd' => 800, 'order_time' => '2026-08-31T16:00:00Z']);
        $this->order(['classification' => 'offline', 'influencer_name' => 'Ann', 'amount_usd' => 700]);
        $response = $this->getJson('/api/workbench/influencers/report?month=2026-08')->assertOk()
            ->assertJsonPath('data.month', '2026-08')
            ->assertJsonPath('data.totals.amountUsd', 0.3)
            ->assertJsonPath('data.totals.orders', 2)
            ->assertJsonPath('data.totals.items', 4)
            ->assertJsonPath('data.list.0.name', 'Ann')
            ->assertJsonPath('data.list.0.sampleValue', null);
        $ranked = array_column($response->json('data.list'), null, 'name');
        $this->assertSame(0, $ranked['Zero']['orders']);
        $this->assertSame(0, $ranked['NoWebsite']['orders']);
        $this->getJson('/api/workbench/influencers/report?month=2026-09')->assertOk()->assertJsonPath('data.totals.orders', 1);
        $this->getJson('/api/workbench/influencers/report?month=2026-13')->assertUnprocessable();
        $this->loginWith(['business.influencer.list']);
        $this->getJson('/api/workbench/influencers/report?month=2026-08')->assertForbidden();
        $this->loginWith(['business.influencer.statistics']);
        $this->getJson('/api/workbench/influencers/report?month=2026-08')->assertOk();
        $this->getJson('/api/workbench/influencers/directory')->assertForbidden();
        $this->postJson('/api/workbench/influencers/domains', ['domain' => 'new.test', 'influencer' => 'Ann'])->assertForbidden();
    }

    public function test_influencer_directory_merges_source_rules_with_database_overrides(): void
    {
        config(['influencer' => ['domains' => ['first.test' => 'Ann', 'second.test' => 'Ann', 'overridden.test' => 'Old', 'deleted.test' => 'Old'], 'tiers' => ['Ann' => 'top', 'Bob' => 'mid']]]);
        \App\Models\InfluencerDomain::create(['domain' => 'overridden.test', 'influencer_name' => 'Bob', 'confirmed' => true]);
        \App\Models\InfluencerDomain::create(['domain' => 'deleted.test', 'influencer_name' => 'Old', 'confirmed' => true, 'deleted_at' => now()]);
        $directory = $this->getJson('/api/workbench/influencers/directory')->assertOk()->json('data.list');
        $this->assertSame(['Ann', 'Bob'], array_column($directory, 'name'));
        $this->assertSame('top', $directory[0]['tier']);
        $this->assertCount(2, $directory[0]['domains']);
        $this->getJson('/api/workbench/influencers/directory?keyword=first.test')->assertOk()->assertJsonCount(2, 'data.list.0.domains');
        $this->postJson('/api/workbench/influencers/domains', ['domain' => 'https://www.first.test/?utm=1', 'influencer' => 'Bob'])->assertConflict();
        $this->postJson('/api/workbench/influencers/domains', ['domain' => 'https://www.first.test/?utm=1', 'influencer' => 'Ann'])->assertOk()->assertJsonPath('data.added', false);
        $this->postJson('/api/workbench/influencers/domains', ['domain' => 'https://www.new.test?utm=1', 'influencer' => 'Ann'])->assertOk()->assertJsonPath('data.domain', 'new.test')->assertJsonPath('data.added', true);
        $csv = $this->get('/api/workbench/influencers/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('first.test', $csv);
        $this->assertStringContainsString('overridden.test', $csv);
        $this->assertStringNotContainsString('deleted.test', $csv);
    }

    public function test_logistics_requires_independent_permission(): void
    {
        Http::fake();
        $this->loginWith(['business.procurement.list']);
        $this->postJson('/api/workbench/procurement/logistics/refresh', ['requestId' => (string) \Illuminate\Support\Str::uuid()])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_logistics_missing_key_is_not_reported_as_success(): void
    {
        config(['collector.token' => 'test-token', 'collector.url' => 'http://collector.test']);
        Http::fake(['collector.test/*' => Http::response(['error' => 'LOGISTICS_NOT_CONFIGURED'], 422)]);
        $this->postJson('/api/workbench/procurement/logistics/refresh', ['requestId' => (string) \Illuminate\Support\Str::uuid()])->assertUnprocessable();
    }

    public function test_logistics_proxy_checks_local_job_and_records_actor(): void
    {
        config(['collector.token' => 'test-token', 'collector.url' => 'http://collector.test', 'collector.schema' => $this->schema]);
        DB::statement('CREATE TABLE jobs (id text PRIMARY KEY, account text, mode text, actor text)');
        $jobId = (string) \Illuminate\Support\Str::uuid();
        DB::table('jobs')->insert(['id' => $jobId, 'account' => config('collector.account'), 'mode' => 'logistics', 'actor' => 'saveb-api:' . $this->admin->id]);
        Http::fake(['collector.test/*' => Http::response(['jobId' => $jobId, 'status' => 'queued'], 202)]);
        $requestId = (string) \Illuminate\Support\Str::uuid();
        $this->postJson('/api/workbench/procurement/logistics/refresh', ['requestId' => $requestId])->assertStatus(202)->assertJsonPath('data.jobId', $jobId);
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', $requestId) && $request->hasHeader('X-Collector-Actor', 'saveb-api:' . $this->admin->id));
        $this->assertSame(1, BusinessOperationLog::where('action', 'refresh-logistics')->count());
    }

    private function loginWith(array $codes): User
    {
        $n = bin2hex(random_bytes(4));
        $role = Role::create(['name' => $n,'code' => $n,'status' => 1]);
        $role->permissions()->sync(Permission::whereIn('code', $codes)->pluck('id'));
        $user = User::create(['username' => $n,'display_name' => $n,'password_hash' => Hash::make('Test-123'),'active' => 1,'role_id' => $role->id]);
        $this->withHeader('Authorization', 'Bearer ' . ApiToken::issue($user->id)['plain']);

        return $user;
    }

    private function order(array $data = []): Order
    {
        return Order::create(array_merge(['order_id' => 'O-' . bin2hex(random_bytes(4)),'visible_order_id' => random_int(1, 1000000),'customer_name' => 'Buyer','classification' => 'offline','order_time' => '2026-08-01T02:00:00Z','amount_original' => 100,'amount_usd' => 100,'currency' => 'USD','items_count' => 2,'product_name' => 'Bag','order_status' => 'completed','staff_code' => 'AA','version' => 1], $data));
    }

    private function attachment(?int $owner = null): Attachment
    {
        $path = 'test-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($this->files . '/' . $path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jhS8AAAAASUVORK5CYII='));

        return Attachment::create(['file_path' => $path,'mime' => 'image/png','size_bytes' => filesize($this->files . '/' . $path),'sha256' => hash_file('sha256', $this->files . '/' . $path),'entity_type' => 'invoice_upload','owner_user_id' => $owner ?? $this->admin->id]);
    }

    private function invoiceData(int $attachment): array
    {
        return ['invoice_date' => '2026-08-01','order_date' => '2026-08-01','customer_full_name' => 'Buyer','customer_email' => 'buyer@example.test','invoice_link' => 'https://example.test/invoice','recipient_paypal' => 'sales@example.test','amount_usd' => '200.00','expedited_shipping' => false,'gift_box' => 'Has','invoice_screenshot_attachment_id' => $attachment,'items' => [['product_name' => 'Bag','quantity' => 2,'price' => '100.00']],'allocations' => [['staff_code' => 'AA','percent' => 100,'commission_percent' => 0]]];
    }

    public function test_module_permissions_are_independent(): void
    {
        $this->loginWith(['business.procurement.statistics']);
        $this->getJson('/api/workbench/procurement/statistics')->assertOk();
        $this->getJson('/api/workbench/procurement')->assertForbidden();
        $this->postJson('/api/workbench/procurement', [])->assertForbidden();
        foreach (['/invoices','/paypal','/warehouse','/operations','/influencers/directory','/sa-sales/bounds'] as $path) {
            $this->getJson('/api/workbench' . $path)->assertForbidden();
        }
    }

    public function test_procurement_handover_inspection_shipping_and_version(): void
    {
        $task = ['productName' => 'Bag','quantity' => 2,'purchaseStatus' => 'warehouse_arrived','cost' => 10];
        $created = $this->postJson('/api/workbench/procurement', $task)->assertOk();
        $id = $created->json('data.id');
        $warehouse = $this->getJson('/api/workbench/warehouse?scope=all')->assertOk()->assertJsonPath('data.total', 1);
        $wid = $warehouse->json('data.list.0.id');
        $payload = ['version' => 1,'status' => 'shipped','items' => [['inspection' => 'pending','shippedQuantity' => 2,'outboundTracking' => 'TRACK-1']]];
        $this->postJson('/api/workbench/warehouse/' . $wid . '/actions', $payload)->assertUnprocessable();
        $payload['items'][0]['inspection'] = 'passed';
        $payload['items'][0]['shippedQuantity'] = 3;
        $this->postJson('/api/workbench/warehouse/' . $wid . '/actions', $payload)->assertUnprocessable();
        $payload['items'][0]['shippedQuantity'] = 2;
        $this->postJson('/api/workbench/warehouse/' . $wid . '/actions', $payload)->assertOk()->assertJsonPath('data.version', 2);
        $this->postJson('/api/workbench/warehouse/' . $wid . '/actions', $payload)->assertConflict();
        $this->getJson('/api/workbench/procurement')->assertOk()->assertJsonPath('data.list.0.purchaseStatus', 'shipped')->assertJsonPath('data.list.0.version', 2);
        $this->deleteJson('/api/workbench/procurement/' . $id, ['version' => 2])->assertUnprocessable();
        $this->assertSame(1, BusinessOperationLog::where('module', 'warehouse')->count());
    }

    public function test_source_order_duplicate_task_and_unknown_source_are_rejected(): void
    {
        $order = $this->order();
        $data = ['sourceKey' => 'order:' . $order->id,'productName' => 'Bag','quantity' => 2,'purchaseStatus' => 'pending_purchase'];
        $this->postJson('/api/workbench/procurement', $data)->assertOk();
        $this->postJson('/api/workbench/procurement', $data)->assertConflict();
        $data['sourceKey'] = 'order:9999';
        $this->postJson('/api/workbench/procurement', $data)->assertUnprocessable();
        $this->getJson('/api/workbench/procurement')->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_unassigned_procurement_order_can_be_removed_without_deleting_the_sales_order(): void
    {
        $order = $this->order();
        $sourceKey = 'order:' . $order->id;
        $this->getJson('/api/workbench/procurement')->assertJsonPath('data.total', 1);
        $this->deleteJson('/api/workbench/procurement/source', ['sourceKey' => $sourceKey])->assertOk();
        $this->getJson('/api/workbench/procurement')->assertJsonPath('data.total', 0);
        $this->getJson('/api/workbench/procurement/statistics')->assertJsonPath('data.total', 0);
        $this->assertTrue(Order::whereKey($order->id)->exists());
        $this->assertSame(0, DB::table('procurement_tasks')->count());
        $this->assertSame($sourceKey, DB::table('procurement_removed_orders')->value('order_id'));
        $this->assertSame(1, BusinessOperationLog::where('module', 'procurement')->where('action', 'delete')->count());

        $this->postJson('/api/workbench/procurement', [
            'sourceKey' => $sourceKey, 'productName' => 'Bag', 'quantity' => 2, 'purchaseStatus' => 'pending_purchase',
        ])->assertUnprocessable();
    }

    public function test_source_removal_rejects_a_stale_row_after_its_task_was_created(): void
    {
        $order = $this->order();
        $sourceKey = 'order:' . $order->id;
        $created = $this->postJson('/api/workbench/procurement', [
            'sourceKey' => $sourceKey, 'productName' => 'Bag', 'quantity' => 2, 'purchaseStatus' => 'pending_purchase',
        ])->assertOk();
        $this->deleteJson('/api/workbench/procurement/source', ['sourceKey' => $sourceKey])->assertConflict();
        $this->deleteJson('/api/workbench/procurement/source', ['sourceKey' => 'order:999999'])->assertUnprocessable();
        $this->assertSame(0, DB::table('procurement_removed_orders')->count());
        $this->getJson('/api/workbench/procurement')->assertJsonPath('data.list.0.id', $created->json('data.id'));
    }

    public function test_source_removal_requires_delete_permission_without_requiring_create_permission(): void
    {
        $order = $this->order();
        $this->loginWith(['business.procurement.list', 'business.procurement.create']);
        $this->deleteJson('/api/workbench/procurement/source', ['sourceKey' => 'order:' . $order->id])->assertForbidden();
        $this->loginWith(['business.procurement.delete']);
        $this->deleteJson('/api/workbench/procurement/source', ['sourceKey' => 'order:' . $order->id])->assertOk();
        $this->assertTrue(Order::whereKey($order->id)->exists());
    }

    public function test_warehouse_snapshot_cannot_be_overridden_and_tracks_purchase_changes(): void
    {
        $data = ['productName' => 'Bag','quantity' => 2,'purchaseStatus' => 'pending_purchase'];
        $id = $this->postJson('/api/workbench/procurement', $data)->assertOk()->json('data.id');
        $data['version'] = 1;
        $data['quantity'] = 3;
        $data['purchaseStatus'] = 'warehouse_arrived';
        $this->putJson('/api/workbench/procurement/' . $id, $data)->assertOk();
        $w = $this->getJson('/api/workbench/warehouse?scope=all')->assertOk()->assertJsonPath('data.list.0.items.0.quantity', 3)->json('data.list.0');
        $this->postJson('/api/workbench/warehouse/' . $w['id'] . '/actions', ['version' => 1,'status' => 'partially_shipped','items' => [['name' => 'Tampered','quantity' => 1,'inspection' => 'passed','shippedQuantity' => 1,'outboundTracking' => 'TRACK']]])->assertOk();
        $this->getJson('/api/workbench/warehouse?scope=all')->assertOk()->assertJsonPath('data.list.0.items.0.quantity', 3)->assertJsonPath('data.list.0.items.0.name', 'Bag')->assertJsonPath('data.list.0.status', 'partially_shipped');
        $data['version'] = 2;
        $data['quantity'] = 4;
        $this->putJson('/api/workbench/procurement/' . $id, $data)->assertUnprocessable();
    }

    public function test_multi_product_handover_preserves_each_item(): void
    {
        $data = ['productName' => 'Bag / Shoe','quantity' => 3,'products' => [['name' => 'Bag','quantity' => 1],['name' => 'Shoe','quantity' => 2]],'purchaseStatus' => 'warehouse_arrived'];
        $this->postJson('/api/workbench/procurement', $data)->assertOk();
        $w = $this->getJson('/api/workbench/warehouse?scope=all')->assertOk()->assertJsonCount(2, 'data.list.0.items')->json('data.list.0');
        $payload = ['version' => 1,'status' => 'shipped','items' => [['inspection' => 'passed','shippedQuantity' => 1,'outboundTracking' => 'BAG'],['inspection' => 'passed','shippedQuantity' => 1,'outboundTracking' => 'SHOE']]];
        $this->postJson('/api/workbench/warehouse/' . $w['id'] . '/actions', $payload)->assertOk();
        $this->getJson('/api/workbench/warehouse?scope=all')->assertOk()->assertJsonPath('data.list.0.status', 'partially_shipped');
    }

    public function test_invoice_binding_allocation_and_optimistic_lock(): void
    {
        $a = $this->attachment();
        $data = $this->invoiceData($a->id);
        $bad = $data;
        $bad['allocations'][0]['percent'] = 90;
        $this->postJson('/api/workbench/invoices', $bad)->assertUnprocessable();
        $data['items'][0]['invoice_id'] = 999999;
        $data['items'][0]['id'] = 999999;
        $created = $this->postJson('/api/workbench/invoices', $data)->assertOk()->assertJsonPath('data.order_number', '10000');
        $id = $created->json('data.id');
        $this->assertSame($id, (int)$created->json('data.items.0.invoice_id'));
        $this->assertNotSame(999999, $created->json('data.items.0.id'));
        $this->assertSame($id, (int)$a->fresh()->entity_id);
        $this->postJson('/api/workbench/invoices', $data)->assertForbidden();
        $data['version'] = 1;
        $data['invoice_date'] = '2026-09-07';
        $data['order_date'] = '2026-09-06';
        $this->putJson('/api/workbench/invoices/' . $id, $data)->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.invoice_date', '2026-08-01')
            ->assertJsonPath('data.order_date', '2026-09-06');
        $this->putJson('/api/workbench/invoices/' . $id, $data)->assertConflict();
        $this->getJson('/api/workbench/invoices')->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_invoice_status_is_validated_instead_of_silently_changed_to_paid(): void
    {
        $data = $this->invoiceData($this->attachment()->id);
        foreach (['Pending', 'Overdue', 'Unpaid', 'Refunded', 'Failed'] as $status) {
            $data['invoice_status'] = $status;
            $this->postJson('/api/workbench/invoices', $data)->assertUnprocessable();
        }
        $this->assertSame(0, \App\Models\InvoiceOrder::count());
        $data['invoice_status'] = 'Paid';
        $this->postJson('/api/workbench/invoices', $data)->assertOk()->assertJsonPath('data.invoice_status', 'Paid');
    }

    public function test_invoice_paste_and_form_options_use_editor_permissions_and_do_not_save(): void
    {
        $this->loginWith(['business.invoice.create']);
        $text = "Date Added: 2026-08-01\nDate Ordered: 2026-09-07\nInvoice Status: Paid\nItems Quantity Price Amount\nBag 2 US$100.00 US$200.00\nTote 1 US$50.00 US$50.00\nTotal USD 250.00";
        $this->postJson('/api/workbench/invoices/parse-text', ['text' => $text])->assertOk()
            ->assertJsonPath('data.fields.order_date', '2026-09-07')
            ->assertJsonPath('data.fields.invoice_status', 'Paid')
            ->assertJsonCount(2, 'data.fields.items')
            ->assertJsonPath('data.fields.items.1.price', 50);
        $this->postJson('/api/workbench/invoices/parse-text', ['text' => str_repeat('x', 30001)])->assertUnprocessable();
        $this->getJson('/api/workbench/invoices/form-options')->assertOk()->assertJsonPath('data.ratesToUsd.USD', 1);
        $this->assertSame(0, \App\Models\InvoiceOrder::count());
        $this->loginWith(['business.invoice.list']);
        $this->postJson('/api/workbench/invoices/parse-text', ['text' => $text])->assertForbidden();
        $this->getJson('/api/workbench/invoices/form-options')->assertForbidden();
        $this->loginWith(['business.invoice.update']);
        $this->postJson('/api/workbench/invoices/parse-text', ['text' => $text])->assertOk();
        $this->getJson('/api/workbench/invoices/form-options')->assertOk();
    }

    public function test_foreign_attachment_is_private_and_cannot_be_attached(): void
    {
        $a = $this->attachment();
        $this->loginWith(['business.invoice.create']);
        $this->get('/api/workbench/attachments/' . $a->id)->assertForbidden();
        $this->postJson('/api/workbench/invoices', $this->invoiceData($a->id))->assertForbidden();
    }

    public function test_uploaded_image_is_validated_and_owned(): void
    {
        $fixture = $this->attachment();
        $file = new UploadedFile($this->files . '/' . $fixture->file_path, 'invoice.png', 'image/png', null, true);
        $result = $this->post('/api/workbench/attachments', ['file' => $file])->assertOk();
        $this->assertSame($this->admin->id, (int)Attachment::findOrFail($result->json('data.id'))->owner_user_id);
        $firstId = $result->json('data.id');
        $firstAttachment = Attachment::findOrFail($firstId);
        $otherUser = $this->loginWith(['business.invoice.create']);
        $copyPath = $this->files . '/duplicate-upload.png';
        copy($this->files . '/' . $firstAttachment->file_path, $copyPath);
        $duplicate = new UploadedFile($copyPath, 'invoice.png', 'image/png', null, true);
        $secondResult = $this->post('/api/workbench/attachments', ['file' => $duplicate])->assertOk();
        $secondAttachment = Attachment::findOrFail($secondResult->json('data.id'));
        $this->assertNotSame($firstId, $secondAttachment->id);
        $this->assertSame($otherUser->id, (int) $secondAttachment->owner_user_id);
        $this->assertSame($firstAttachment->sha256, $secondAttachment->sha256);
        $this->get('/api/workbench/attachments/' . $firstId)->assertForbidden();
        $this->post('/api/workbench/attachments', ['file' => UploadedFile::fake()->create('bad.html', 1, 'text/html')], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_paypal_withdrawal_cannot_be_replayed_and_balance_rebases(): void
    {
        $account = $this->postJson('/api/workbench/paypal', ['email' => 'sales@example.test','accountName' => 'Sales','balance' => 100,'reviews' => 0])->assertOk();
        $id = $account->json('data.id');
        $this->postJson('/api/workbench/paypal', ['email' => 'SALES@EXAMPLE.TEST','accountName' => 'Duplicate','balance' => 0,'reviews' => 0])->assertUnprocessable();
        $this->order(['receiving_paypal' => 'sales@example.test']);
        $this->getJson('/api/workbench/paypal')->assertOk()->assertJsonPath('data.list.0.balance', 200);
        $data = ['version' => 1,'amount' => 20,'date' => '2026-08-01'];
        $this->postJson('/api/workbench/paypal/' . $id . '/withdrawal', $data)->assertOk();
        $this->postJson('/api/workbench/paypal/' . $id . '/withdrawal', $data)->assertConflict();
        $this->getJson('/api/workbench/paypal')->assertOk()->assertJsonPath('data.list.0.balance', 180);
        $this->postJson('/api/workbench/paypal/' . $id . '/balance', ['version' => 2,'amount' => 50])->assertOk();
        $this->getJson('/api/workbench/paypal')->assertOk()->assertJsonPath('data.list.0.balance', 50);
    }

    public function test_paypal_legacy_catalog_restores_dates_balances_reviews_and_imported_totals(): void
    {
        $account = \App\Models\PaypalAccount::create(['email' => 'legacy@example.test', 'active' => true, 'version' => 1, 'meta' => []]);
        \App\Models\PaypalBalanceEntry::create(['account_id' => $account->id, 'balance' => 1]);
        \App\Models\PaypalWithdrawal::create(['account_id' => $account->id, 'amount' => 20, 'withdrawn_at' => '2026-08-31']);
        \App\Models\SystemState::create(['key' => 'paypal_legacy_state', 'value' => [
            'balances' => ['legacy@example.test' => ['base' => 1000, 'baselineReceived' => 100, 'updatedAt' => '2026-07-31T10:00:00Z']],
            'withdrawals' => ['legacy@example.test' => ['entries' => [['amount' => 20, 'createdAt' => '2026-08-01T10:00:00Z']]]],
            'changeLogs' => [['createdAt' => '2026-07-31T10:00:00Z', 'accountName' => 'Legacy account log', 'field' => 'balance', 'previousValue' => 900, 'newValue' => 1000, 'updatedBy' => 'legacy-admin']],
        ]]);
        $this->order(['receiving_paypal' => 'legacy@example.test', 'amount_usd' => 200, 'order_status' => 'pending']);
        $this->order(['receiving_paypal' => 'missing@example.test', 'amount_usd' => 80]);
        $legend = ['totalWithdrewImportedAt' => '2026-07-01 07:45:14', 'rows' => [
            ['email' => 'legacy@example.test', 'accountName' => 'Legacy', 'addedDate' => '2025.10.25', 'numberOfReviews' => 3, 'importedTotalWithdrew' => 500],
            ['email' => 'missing@example.test', 'accountName' => 'Missing', 'addedDate' => '2026-07-22T00:00:00.000Z'],
        ]];
        $service = app(\App\Services\PaypalLegacyService::class);
        $preview = $service->restore($legend, false);
        $this->assertSame(2, $preview['dates']);
        $this->assertNull($account->fresh()->added_date);
        $restored = $service->restore($legend, true);
        $this->assertSame(1, $restored['created']);
        $this->assertSame(2, $restored['dated']);
        $this->assertSame('2025-10-25', $account->fresh()->added_date);
        $this->assertSame(0, $service->restore($legend, true)['dated']);
        $this->getJson('/api/workbench/paypal')->assertOk()->assertJsonPath('data.list.0.balance', 1080)
            ->assertJsonPath('data.list.0.withdrawn', 520)->assertJsonPath('data.list.0.reviews', 3)->assertJsonPath('data.list.0.addedDate', '2025-10-25');
        $this->getJson('/api/workbench/paypal?keyword=missing')->assertOk()->assertJsonPath('data.list.0.balance', 0)->assertJsonPath('data.list.0.received', 80);
        $this->getJson('/api/workbench/paypal/withdrawals?startDate=2026-09-01&endDate=2026-09-08')
            ->assertOk()->assertJsonPath('data.count', 1)->assertJsonPath('data.list.0.imported', true)->assertJsonPath('data.amount', 500);
        $this->getJson('/api/workbench/paypal/statistics?mode=monthly')->assertOk()
            ->assertJsonPath('data.0.period', '2026-08')->assertJsonPath('data.0.amount', 20);
        $this->assertStringContainsString('legacy-admin', $this->get('/api/workbench/paypal/logs/export')->assertOk()->streamedContent());
        $this->postJson('/api/workbench/paypal/' . $account->id . '/withdrawal', ['version' => 1, 'amount' => 30, 'date' => '2026-09-01'])->assertOk();
        $this->getJson('/api/workbench/paypal')->assertOk()->assertJsonPath('data.list.0.balance', 1050)->assertJsonPath('data.list.0.withdrawn', 550);
        $this->postJson('/api/workbench/paypal/' . $account->id . '/balance', ['version' => 2, 'amount' => 80])->assertOk();
        $this->postJson('/api/workbench/paypal/' . $account->id . '/review', ['version' => 3, 'value' => 0])->assertOk();
        $this->getJson('/api/workbench/paypal')->assertOk()->assertJsonPath('data.list.0.balance', 80)->assertJsonPath('data.list.0.reviews', 0);
        $service->restore($legend, true);
        $this->getJson('/api/workbench/paypal')->assertOk()->assertJsonPath('data.list.0.balance', 80);
        $this->assertDatabaseCount('paypal_withdrawals', 2);
    }

    public function test_paypal_activity_matches_original_canonical_and_later_legacy_scope(): void
    {
        $this->order(['receiving_paypal' => 'activity@example.test', 'amount_usd' => 10, 'order_status' => 'pending']);
        \App\Models\InvoiceOrder::create(['order_number' => 'EXCLUDED-INVOICE', 'recipient_paypal' => 'activity@example.test', 'invoice_date' => '2026-08-01', 'invoice_status' => 'Paid', 'amount_usd' => 900]);
        \App\Models\SystemState::create(['key' => 'legacy_dashboard_context', 'value' => ['exchangeRates' => ['EUR' => 0.8]]]);
        foreach (['2026-08-01', '2026-08-02'] as $date) {
            $paid = ['clientOrderId' => 'LEGACY', 'recipientPaypal' => 'activity@example.test', 'paymentStatus' => 'completed', 'amount' => 80, 'currency' => 'EUR', 'createTime' => $date . 'T12:00:00Z'];
            \App\Models\LegacyDashboardDay::create(['day' => $date, 'source_sha256' => str_repeat('a', 64), 'source_size_bytes' => 1,
                'snapshot_cutoff_asia_shanghai' => '2026-08-02', 'payload' => ['orders' => [$paid, $paid, array_merge($paid, ['clientOrderId' => 'PENDING', 'paymentStatus' => 'pending'])]]]);
        }
        $rows = $this->getJson('/api/workbench/paypal/orders?email=activity@example.test')->assertOk()->assertJsonCount(2, 'data.list')->json('data.list');
        $this->assertEquals(110, array_sum(array_column($rows, 'amountUsd')));
        $this->assertSame('LEGACY', $rows[0]['orderId']);
    }

    public function test_paypal_import_cutoff_uses_original_shanghai_timezone(): void
    {
        \App\Models\SystemState::create(['key' => 'paypal_monitor_catalog', 'value' => ['totalWithdrewImportedAt' => '2026-07-01 07:45:14']]);
        \App\Models\SystemState::create(['key' => 'paypal_legacy_state', 'value' => ['withdrawals' => ['clock@example.test' => ['entries' => [
            ['amount' => 10, 'createdAt' => '2026-06-30T23:40:00Z'],
            ['amount' => 20, 'createdAt' => '2026-06-30T23:50:00Z'],
        ]]]]]);
        $service = app(\App\Services\PaypalLegacyService::class);
        $this->assertCount(1, $service->entries('clock@example.test', true));
        $this->assertSame(20.0, $service->localWithdrawn('clock@example.test'));
    }

    public function test_paypal_monitor_filters_statistics_logs_and_balance_limits(): void
    {
        $id = $this->postJson('/api/workbench/paypal', ['email' => 'monitor@example.test', 'accountName' => 'Monitor', 'balance' => 100, 'reviews' => 0])
            ->assertOk()->json('data.id');
        $this->postJson("/api/workbench/paypal/$id/withdrawal", ['version' => 1, 'amount' => 101, 'date' => '2026-08-01'])->assertUnprocessable();
        $this->assertDatabaseCount('paypal_withdrawals', 0);
        $this->postJson("/api/workbench/paypal/$id/balance", ['version' => 1, 'amount' => -1])->assertUnprocessable();
        foreach ([['2026-08-01', 0.1], ['2026-08-01', 0.2], ['2026-08-02', 10], ['2026-07-31', 5]] as $index => [$date, $amount]) {
            $this->postJson("/api/workbench/paypal/$id/withdrawal", ['version' => $index + 1, 'amount' => $amount, 'date' => $date])->assertOk();
        }
        $this->getJson('/api/workbench/paypal/withdrawals?keyword=MONITOR&startDate=2026-08-01&endDate=2026-08-02')
            ->assertOk()->assertJsonPath('data.count', 3)->assertJsonPath('data.amount', 10.3)
            ->assertJsonPath('data.list.0.date', '2026-08-02');
        $this->getJson('/api/workbench/paypal/withdrawals?keyword=absent')->assertOk()->assertJsonPath('data.count', 0);
        $this->getJson('/api/workbench/paypal/statistics?mode=daily&startDate=2026-08-01&endDate=2026-08-02')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.period', '2026-08-01')->assertJsonPath('data.0.amount', 0.3);
        $this->getJson('/api/workbench/paypal/statistics?mode=monthly')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.period', '2026-08')->assertJsonPath('data.1.amount', 10.3);
        $this->getJson('/api/workbench/paypal/statistics?mode=yearly')->assertUnprocessable();
        $this->getJson('/api/workbench/paypal/withdrawals?startDate=2026-08-02&endDate=2026-08-01')->assertUnprocessable();
        $log = BusinessOperationLog::where('module', 'paypal')->where('action', 'withdrawal')->orderBy('id')->first();
        $this->assertEquals(100, $log->before['balance']);
        $this->assertEquals(99.9, $log->after['balance']);
        $this->assertArrayNotHasKey('withdrawals', $this->getJson('/api/workbench/paypal')->assertOk()->json('data.list.0'));
        $csv = $this->get('/api/workbench/paypal/logs/export?locale=en-US')->assertOk()->streamedContent();
        $this->assertStringContainsString('"Previous Value"', $csv);
        $this->assertStringContainsString('99.9', $csv);
        $this->order(['receiving_paypal' => 'monitor@example.test', 'source_site' => 'monitor.test']);
        $orders = $this->get('/api/workbench/paypal/orders/export?email=monitor@example.test&locale=en-US')->assertOk()->streamedContent();
        $this->assertStringContainsString('monitor.test', $orders);
        $this->assertStringContainsString('completed', $orders);
    }

    public function test_paypal_monitor_permissions_are_independent_and_migration_is_idempotent(): void
    {
        $permission = Permission::where('code', 'business.paypal.statistics')->firstOrFail();
        $permission->update(['name_zh' => '自定义统计名称']);
        (require database_path('migrations/2026_09_08_180000_add_paypal_monitor_permissions.php'))->up();
        $this->assertSame('自定义统计名称', $permission->fresh()->name_zh);
        $this->loginWith(['business.paypal.list']);
        foreach (['withdrawals', 'statistics?mode=daily', 'logs', 'logs/export', 'orders/export?email=sales@example.test'] as $path) {
            $this->getJson('/api/workbench/paypal/' . $path)->assertForbidden();
        }
        $this->loginWith(['business.paypal.withdrawals']);
        $this->getJson('/api/workbench/paypal/withdrawals')->assertOk();
        $this->postJson('/api/workbench/paypal/1/withdrawal', [])->assertForbidden();
        $this->getJson('/api/workbench/paypal/statistics?mode=daily')->assertForbidden();
        $this->loginWith(['business.paypal.statistics']);
        $this->getJson('/api/workbench/paypal/statistics?mode=monthly')->assertOk();
        $this->getJson('/api/workbench/paypal/withdrawals')->assertForbidden();
        $this->loginWith(['business.paypal.logs']);
        $this->getJson('/api/workbench/paypal/logs')->assertOk();
        $this->get('/api/workbench/paypal/logs/export')->assertOk();
        $this->get('/api/workbench/paypal/export')->assertForbidden();
        $this->loginWith(['business.paypal.orders_export']);
        $this->getJson('/api/workbench/paypal/orders/export?email=sales@example.test')->assertForbidden();
        $this->loginWith(['business.paypal.orders', 'business.paypal.orders_export']);
        $this->get('/api/workbench/paypal/orders/export?email=sales@example.test')->assertOk();
    }

    public function test_influencer_directory_normalization_conflict_and_export(): void
    {
        $this->postJson('/api/workbench/influencers/domains', ['domain' => 'https://www.example.test/shop','influencer' => 'Ann'])->assertOk()->assertJsonPath('data.domain', 'example.test');
        $this->postJson('/api/workbench/influencers/domains', ['domain' => 'example.test','influencer' => 'Bob'])->assertConflict();
        $this->getJson('/api/workbench/influencers/directory?keyword=Ann')->assertOk()->assertJsonPath('data.list.0.name', 'Ann');
        $this->get('/api/workbench/influencers/export')->assertOk();
    }

    public function test_sa_order_details_default_to_regular_and_invoice_requires_classification(): void
    {
        $this->order(['order_id' => 'REGULAR-PAID', 'classification' => 'official', 'amount_usd' => 100.12]);
        $this->order(['order_id' => 'REGULAR-PENDING', 'order_status' => 'pending', 'amount_usd' => 50.25]);
        $this->order(['order_id' => 'REGULAR-REFUND', 'order_status' => 'refunded', 'amount_usd' => 20.10]);
        $this->order(['order_id' => 'LEGACY-ONLY', 'classification' => 'invoice']);
        $this->order(['order_id' => 'INV-ONLY', 'classification' => 'invoice']);
        $this->order(['order_id' => 'HIDDEN', 'customer_name' => 'test Buyer']);
        \App\Models\InvoiceOrder::create([
            'order_number' => 'INV-ONLY', 'invoice_date' => '2026-08-01', 'order_date' => '2026-08-01',
            'customer_full_name' => 'Invoice Buyer', 'invoice_status' => 'Paid', 'amount_usd' => 88.88,
            'gift_box' => 'Has',
        ]);
        $regular = $this->getJson('/api/workbench/sa-sales/orders?per_page=2')
            ->assertOk()->assertJsonPath('data.total', 3)->assertJsonCount(2, 'data.list')
            ->assertJsonPath('data.totalAmount', 130.27)->json('data');
        $next = $this->getJson('/api/workbench/sa-sales/orders?per_page=2&page=2')
            ->assertOk()->assertJsonCount(1, 'data.list')->assertJsonPath('data.totalAmount', 130.27)->json('data');
        $details = collect(array_merge($regular['list'], $next['list']))->keyBy('orderId');
        $this->assertSame('pending', $details['REGULAR-PENDING']['status']);
        $this->assertSame(-20.1, $details['REGULAR-REFUND']['amountUsd']);
        $this->assertSame(3, $this->getJson('/api/workbench/sa-sales/orders?classification=')->assertOk()->json('data.total'));
        $invoice = $this->getJson('/api/workbench/sa-sales/orders?classification=invoice')
            ->assertOk()->assertJsonPath('data.total', 2)->json('data.list');
        $this->assertSame(['INV-ONLY', 'LEGACY-ONLY'], collect($invoice)->pluck('orderId')->sort()->values()->all());
        $this->getJson('/api/workbench/sa-sales/orders?classification=official')
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.orderId', 'REGULAR-PAID');
    }

    public function test_sa_order_detail_filters_are_independent_and_match_shared_staff_exactly(): void
    {
        $target = [
            'order_id' => 'TARGET-123', 'customer_name' => 'Alice Smith', 'classification' => 'official',
            'receiving_paypal' => 'SALES@example.test', 'source_site' => 'shop.example.test',
            'order_time' => '2026-07-31T16:00:00Z',
            'raw' => ['staffAllocations' => [['staffCode' => 'AA', 'percent' => 50], ['staffCode' => 'BB', 'percent' => 50]]],
        ];
        $this->order($target);
        foreach ([
            ['order_id' => 'OTHER-123', 'customer_name' => 'Alice Smith TARGET'],
            ['customer_name' => 'Other Buyer'], ['receiving_paypal' => 'other@example.test'],
            ['source_site' => 'other.example.test'], ['classification' => 'offline'],
            ['order_status' => 'pending'], ['order_time' => '2026-07-31T15:59:59Z'],
            ['order_time' => '2026-08-01T16:00:00Z'],
            ['staff_code' => 'BBB', 'raw' => []],
        ] as $index => $difference) {
            $this->order(array_replace($target, ['order_id' => 'TARGET-DECOY-' . $index], $difference));
        }
        $filters = [
            'startDate' => '2026-08-01', 'endDate' => '2026-08-01', 'classification' => 'official',
            'orderStatus' => 'completed', 'orderId' => 'TARGET', 'customerName' => 'alice',
            'customerService' => 'BB', 'paypalAccount' => 'sales@', 'website' => 'SHOP.',
        ];
        $this->getJson('/api/workbench/sa-sales/orders?' . http_build_query($filters))
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.orderId', 'TARGET-123')
            ->assertJsonPath('data.list.0.website', 'shop.example.test')->assertJsonPath('data.list.0.date', '2026-08-01');
        $this->order(['order_id' => 'NO-STAFF', 'staff_code' => '']);
        $this->getJson('/api/workbench/sa-sales/orders?customerService=Unassigned')
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.orderId', 'NO-STAFF');
    }

    public function test_sa_order_options_include_historical_collaborators_and_invoice_staff(): void
    {
        $this->order(['staff_code' => 'aa/CC', 'raw' => ['staffAllocations' => [['staffCode' => 'bb', 'percent' => 100]]]]);
        $this->admin->update(['staff_code' => 'DD']);
        $invoice = \App\Models\InvoiceOrder::create([
            'order_number' => 'STAFF-INVOICE', 'invoice_date' => '2026-08-01',
            'invoice_status' => 'Paid', 'amount_usd' => 10, 'gift_box' => 'Has',
        ]);
        \App\Models\InvoiceStaffAllocation::create(['invoice_id' => $invoice->id, 'staff_code' => 'EE', 'share_ratio' => 1, 'commission_percent' => 0]);
        $this->getJson('/api/workbench/sa-sales/order-options')->assertOk()
            ->assertJsonPath('data.employees', ['AA', 'BB', 'CC', 'DD', 'EE']);
        $this->getJson('/api/workbench/sa-sales/orders?classification=invoice&customerService=EE')
            ->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_sa_detail_endpoints_validate_filters_and_require_sales_permission(): void
    {
        $this->loginWith(['business.sa_sales.list']);
        $this->getJson('/api/workbench/sa-sales/orders')->assertOk();
        $this->getJson('/api/workbench/sa-sales/order-options')->assertOk();
        foreach ([
            ['classification' => 'invalid'], ['orderStatus' => 'invalid'],
            ['startDate' => '2026-08-02', 'endDate' => '2026-08-01'], ['startDate' => '2026-08-01'],
            ['endDate' => '2026-08-01'], ['per_page' => 101], ['page' => 0], ['customerName' => str_repeat('a', 256)],
        ] as $filters) {
            $this->getJson('/api/workbench/sa-sales/orders?' . http_build_query($filters))->assertUnprocessable();
        }
        $this->loginWith(['business.invoice.list']);
        $this->getJson('/api/workbench/sa-sales/orders')->assertForbidden();
        $this->getJson('/api/workbench/sa-sales/order-options')->assertForbidden();
        $this->withHeader('Authorization', '');
        $this->getJson('/api/workbench/sa-sales/orders')->assertUnauthorized();
        $this->getJson('/api/workbench/sa-sales/order-options')->assertUnauthorized();
    }

    public function test_sa_summary_can_skip_details_without_changing_statistics_or_exports(): void
    {
        $this->order(['order_id' => 'EXPORT-ME']);
        $url = '/api/workbench/sa-sales/report?startDate=2026-08-01&endDate=2026-08-31';
        $full = $this->getJson($url)->assertOk()->assertJsonCount(0, 'data.detail')->json('data');
        $summary = $this->getJson($url . '&includeDetails=0')->assertOk()
            ->assertJsonPath('data.detail', [])->assertJsonPath('data.invoiceSales.detail', [])->json('data');
        $this->assertSame($full['metrics'], $summary['metrics']);
        $this->assertSame($full['range'], $summary['range']);
        $csv = $this->get('/api/workbench/sa-sales/export?startDate=2026-08-01&endDate=2026-08-31')->assertOk()->streamedContent();
        $this->assertStringContainsString('EXPORT-ME', $csv);
    }

    public function test_sa_sales_refunds_shared_staff_and_commission_tiers(): void
    {
        $this->order(['amount_usd' => 100,'raw' => ['staffAllocations' => [['staffCode' => 'AA','percent' => 50],['staffCode' => 'BB','percent' => 50]]]]);
        $this->order(['amount_usd' => 40,'order_status' => 'refunded']);
        $this->getJson('/api/workbench/sa-sales/report?startDate=2026-08-01&endDate=2026-08-31')->assertOk()->assertJsonPath('data.metrics.orders', 1)->assertJsonPath('data.metrics.refundOrders', 1)->assertJsonPath('data.metrics.netSales', 60);
        $this->assertSame(1800.0, \App\Services\SaSalesService::commission(90000));
        $csv = $this->get('/api/workbench/sa-sales/export?startDate=2026-08-01&endDate=2026-08-31')->assertOk()->streamedContent();
        $this->assertStringContainsString('员工排行榜', $csv);
        $this->assertStringContainsString('每日统计', $csv);
        $this->assertStringContainsString('Invoice 员工排行榜', $csv);
    }

    public function test_sa_report_matches_source_channels_gross_shares_and_refund_amounts(): void
    {
        $this->order(['amount_usd' => 100, 'receiving_paypal' => 'SALES@EXAMPLE.TEST', 'raw' => [
            'platform' => 'Retail', 'paymentMethod' => 'Card',
            'staffAllocations' => [['staffCode' => 'AA', 'percent' => 25], ['staffCode' => 'BB', 'percent' => 75]],
        ]]);
        $this->order(['amount_usd' => 80, 'order_status' => 'refunded', 'raw' => ['platform' => 'Retail', 'paymentMethod' => 'Card']]);
        $this->order(['amount_usd' => 100, 'raw' => ['platform' => 'Wholesale']]);
        $report = $this->getJson('/api/workbench/sa-sales/report?startDate=2026-08-01&endDate=2026-08-31')
            ->assertOk()->assertJsonPath('data.metrics.positiveSales', 200)
            ->assertJsonPath('data.metrics.refundAmount', 80)
            ->assertJsonPath('data.daily.0.sales', 200)
            ->assertJsonPath('data.daily.0.refunds', 80)
            ->assertJsonPath('data.daily.0.netSales', 120)
            ->json('data');
        $channels = collect($report['channels'])->keyBy('name');
        $this->assertEquals(20, $channels['Retail']['netSales']);
        $this->assertEquals(50, $channels['Retail']['sharePercent']);
        $this->assertEquals(50, $channels['Wholesale']['sharePercent']);
        $this->assertSame('Wholesale', $report['channels'][0]['name']);
        $staff = collect($report['employees'])->keyBy('name');
        $this->assertEquals(125, $staff['AA']['sales']);
        $this->assertEquals(80, $staff['AA']['refunds']);
        $this->assertEquals(0.68, $staff['AA']['commission']);
        $this->assertSame('BB', $report['employees'][0]['name']);
        $this->assertCount(4, $report['breakdowns']);
        $this->assertEquals(200, array_sum(array_column($report['breakdowns']['employee'], 'sales')));
        $this->assertSame([], $report['detail']);
        $report['detail'] = $this->getJson('/api/workbench/sa-sales/orders?startDate=2026-08-01&endDate=2026-08-31')->assertOk()->json('data.list');
        $this->assertCount(3, $report['detail']);
        $this->assertContains('Card', array_column($report['detail'], 'paymentMethod'));
        $this->assertContains('sales@example.test', array_column($report['detail'], 'account'));
        $this->assertEquals(120, array_sum(array_column($report['detail'], 'amountUsd')));
        $this->loginWith(['business.invoice.list']);
        $this->getJson('/api/workbench/sa-sales/report?startDate=2026-08-01&endDate=2026-08-31')->assertForbidden();
    }

    public function test_spreadsheet_seed_and_permission_migration_are_idempotent(): void
    {
        $count = Permission::count();
        (require database_path('migrations/2026_09_06_140001_add_workbench_permissions.php'))->up();
        $this->assertSame($count, Permission::count());
        $this->getJson('/api/workbench/operations')->assertOk()->assertJsonCount(5, 'data');
        $this->getJson('/api/workbench/operations?keyword=话术')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_ocr_uses_owned_attachment_and_engine_contract(): void
    {
        $a = $this->attachment();
        config(['business.ocr_url' => 'http://ocr.test']);
        Http::fake(['ocr.test/*' => Http::response(['engine' => 'paddleocr','blocks' => [['text' => 'Paid Total USD 200.00','score' => .99]]])]);
        $this->postJson('/api/workbench/invoice-ocr', ['attachmentId' => $a->id])->assertOk()->assertJsonPath('data.suggestions.amountUsd', 200);
        Http::assertSent(fn ($request) => $request['objectRef'] === $a->file_path);
    }

    public function test_ocr_returns_editable_fields_without_saving_an_invoice(): void
    {
        $attachment = $this->attachment();
        config(['business.ocr_url' => 'http://ocr.test']);
        Http::fake(['ocr.test/*' => Http::response([
            'engine' => 'paddleocr',
            'blocks' => array_map(fn ($text) => ['text' => $text], [
                'Receiving PayPal: seller@example.test', 'Invoice Date: 2026-09-07',
                'Bill To', 'Jane Smith', 'buyer@example.test',
                'Items Quantity Price Amount', 'Bag 2 USD 100.00 USD 200.00',
                'Paid Total USD 200.00', 'Paid',
            ]),
        ])]);
        $this->postJson('/api/workbench/invoice-ocr', ['attachmentId' => $attachment->id])
            ->assertOk()
            ->assertJsonPath('data.fields.order_date', '2026-09-07')
            ->assertJsonPath('data.fields.customer_email', 'buyer@example.test')
            ->assertJsonPath('data.fields.recipient_paypal', 'seller@example.test')
            ->assertJsonPath('data.fields.items.0.quantity', 2)
            ->assertJsonPath('data.fields.items.0.price', 100);
        $this->assertDatabaseCount('invoice_orders', 0);
        $this->loginWith(['business.invoice.list']);
        $this->postJson('/api/workbench/invoice-ocr', ['attachmentId' => $attachment->id])->assertForbidden();
    }

    public function test_invoice_detail_returns_latest_image_bindings_by_record_id_and_requires_permission(): void
    {
        $screenshot = $this->attachment();
        $productImage = $this->attachment();
        $data = $this->invoiceData($screenshot->id);
        $data['items'][0]['image_attachment_id'] = $productImage->id;
        $invoiceId = $this->postJson('/api/workbench/invoices', $data)->assertOk()->json('data.id');
        $listed = $this->getJson('/api/workbench/invoices')->assertOk()->json('data.list.0');

        $replacement = $this->attachment();
        $data['invoice_screenshot_attachment_id'] = $replacement->id;
        $data['version'] = 1;
        $this->putJson('/api/workbench/invoices/' . $invoiceId, $data)->assertOk();
        $this->assertEquals($screenshot->id, $listed['invoice_screenshot_attachment_id']);

        $this->loginWith(['business.invoice.list']);
        $detail = $this->getJson('/api/workbench/invoices/' . $invoiceId)->assertOk()
            ->assertJsonPath('data.id', $invoiceId)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.invoice_screenshot_attachment_id', $replacement->id)
            ->assertJsonPath('data.items.0.image_attachment_id', $productImage->id)
            ->json('data');
        $this->assertArrayNotHasKey('raw', $detail);
        $this->assertArrayNotHasKey('invoice_screenshot_image', $listed);
        $this->assertArrayNotHasKey('image', $listed['items'][0]);
        $this->assertSame('ready', $detail['invoice_screenshot_image']['status']);
        $this->assertSame('data:image/png;base64,' . base64_encode(file_get_contents($this->files . '/' . $replacement->file_path)), $detail['invoice_screenshot_image']['src']);
        $this->assertSame('ready', $detail['items'][0]['image']['status']);
        $this->assertSame('data:image/png;base64,' . base64_encode(file_get_contents($this->files . '/' . $productImage->file_path)), $detail['items'][0]['image']['src']);
        $this->get('/api/workbench/attachments/' . $replacement->id)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/api/workbench/attachments/' . $productImage->id)->assertOk();

        $this->loginWith(['business.invoice.create']);
        $this->getJson('/api/workbench/invoices/' . $invoiceId)->assertForbidden();
        $this->getJson('/api/workbench/attachments/' . $replacement->id)->assertForbidden();
        $this->withHeader('Authorization', '');
        $this->getJson('/api/workbench/invoices/' . $invoiceId)->assertUnauthorized();
    }

    public function test_invoice_detail_embeds_all_product_images_and_handles_invalid_files(): void
    {
        $screenshot = $this->attachment();
        $first = $this->attachment();
        $second = $this->attachment();
        $data = $this->invoiceData($screenshot->id);
        $data['items'][0]['image_attachment_id'] = $first->id;
        $data['items'][] = array_replace($data['items'][0], ['product_name' => 'Second product', 'image_attachment_id' => $second->id]);
        $invoiceId = $this->postJson('/api/workbench/invoices', $data)->assertOk()->json('data.id');
        $this->getJson('/api/workbench/invoices/' . $invoiceId)->assertOk()
            ->assertJsonPath('data.items.0.image.id', $first->id)
            ->assertJsonPath('data.items.1.image.id', $second->id)
            ->assertJsonPath('data.items.0.image.status', 'ready')
            ->assertJsonPath('data.items.1.image.status', 'ready');

        unlink($this->files . '/' . $screenshot->file_path);
        file_put_contents($this->files . '/' . $first->file_path, 'corrupted image');
        $response = $this->getJson('/api/workbench/invoices/' . $invoiceId)->assertOk()
            ->assertJsonPath('data.invoice_screenshot_image.status', 'missing')
            ->assertJsonPath('data.invoice_screenshot_image.src', null)
            ->assertJsonPath('data.items.0.image.status', 'integrity_failed')
            ->assertJsonPath('data.items.0.image.src', null)
            ->assertJsonPath('data.items.1.image.status', 'ready');
        $this->assertStringStartsWith('data:image/png;base64,', $response->json('data.items.1.image.src'));

        // 即使商品错误引用了其他订单的附件，也不能随详情泄露图片。
        $second->refresh()->update(['entity_id' => $invoiceId + 1]);
        $this->getJson('/api/workbench/invoices/' . $invoiceId)->assertOk()
            ->assertJsonPath('data.items.1.image.status', 'forbidden')
            ->assertJsonPath('data.items.1.image.src', null);
    }

    public function test_missing_bound_attachment_is_404_and_does_not_bypass_authorization(): void
    {
        $attachment = $this->attachment();
        $this->postJson('/api/workbench/invoices', $this->invoiceData($attachment->id))->assertOk();
        $this->loginWith(['business.invoice.list']);
        $this->get('/api/workbench/attachments/' . $attachment->id)->assertOk();
        unlink($this->files . '/' . $attachment->file_path);
        $this->getJson('/api/workbench/attachments/' . $attachment->id)
            ->assertNotFound()->assertJsonPath('message', '附件文件不存在');
        $this->loginWith(['business.invoice.create']);
        $this->getJson('/api/workbench/attachments/' . $attachment->id)->assertForbidden();
    }

    public function test_local_screenshot_upload_and_real_ocr_produce_form_fields(): void
    {
        if (!is_executable('/usr/bin/tesseract')) {
            $this->markTestSkipped('Local Docker OCR requires Tesseract.');
        }
        config(['business.ocr_url' => null]);
        $uploadPath = $this->files . '/invoice-fixture.png';
        copy(base_path('tests/Fixtures/invoice-ocr.png'), $uploadPath);
        $file = new UploadedFile($uploadPath, 'invoice-fixture.png', 'image/png', null, true);
        $attachmentId = $this->post('/api/workbench/attachments', ['file' => $file])->assertOk()->json('data.id');
        $this->postJson('/api/workbench/invoice-ocr', ['attachmentId' => $attachmentId])
            ->assertOk()
            ->assertJsonPath('data.engine', 'tesseract')
            ->assertJsonPath('data.fields.customer_full_name', 'Jane Smith')
            ->assertJsonPath('data.fields.customer_email', 'buyer@example.test')
            ->assertJsonPath('data.fields.order_date', '2026-09-07')
            ->assertJsonPath('data.fields.amount_usd', 225)
            ->assertJsonCount(2, 'data.fields.items');
        $this->assertDatabaseCount('invoice_orders', 0);
    }

    /** 本地回归使用用户指定原图，图片本身不放入代码仓库。 */
    public function test_user_paypal_card_screenshot_fills_three_items_and_paid_status(): void
    {
        $samplePath = getenv('INVOICE_OCR_SAMPLE_IMAGE');
        if (!$samplePath || !is_file($samplePath)) {
            $this->markTestSkipped('Set INVOICE_OCR_SAMPLE_IMAGE to the provided PayPal screenshot.');
        }
        $this->assertTrue(extension_loaded('gd'));
        $sourceHash = hash_file('sha256', $samplePath);
        config(['business.ocr_url' => null]);
        $uploadPath = $this->files . '/paypal-cards.png';
        copy($samplePath, $uploadPath);
        $file = new UploadedFile($uploadPath, 'paypal-cards.png', 'image/png', null, true);
        $attachmentId = $this->post('/api/workbench/attachments', ['file' => $file])->assertOk()->json('data.id');
        $temporaryFiles = glob(sys_get_temp_dir() . '/invoice-ocr-*');
        $response = $this->postJson('/api/workbench/invoice-ocr', ['attachmentId' => $attachmentId])
            ->assertOk()
            ->assertJsonPath('data.engine', 'tesseract')
            ->assertJsonPath('data.fields.invoice_status', 'Paid')
            ->assertJsonPath('data.paymentStatus', 'Paid')
            ->assertJsonPath('data.fields.order_date', '2026-08-22')
            ->assertJsonPath('data.fields.amount_usd', 452.86)
            ->assertJsonPath('data.fields.percentage_discount', 5)
            ->assertJsonCount(3, 'data.fields.items');
        $this->assertSame([1, 1, 1], array_column($response->json('data.fields.items'), 'quantity'));
        $this->assertEquals([189, 159, 159], array_column($response->json('data.fields.items'), 'price'));
        $this->assertSame($temporaryFiles, glob(sys_get_temp_dir() . '/invoice-ocr-*'));
        $this->assertSame($sourceHash, hash_file('sha256', $samplePath));
        $this->assertDatabaseCount('invoice_orders', 0);
    }

    public function test_read_permission_does_not_grant_exports_or_financial_writes(): void
    {
        $this->loginWith(['business.paypal.list','business.invoice.list','business.procurement.list','business.sa_sales.list','business.influencer.list']);
        foreach (['/paypal/export','/procurement/export','/sa-sales/export?startDate=2026-08-01&endDate=2026-08-31','/influencers/export'] as $url) {
            $this->get('/api/workbench' . $url)->assertForbidden();
        }
        $this->postJson('/api/workbench/paypal/1/withdrawal',[])->assertForbidden();
    }
}
