<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderManagementTest extends TestCase
{
    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RBAC_TEST_POSTGRES') !== '1') {
            $this->markTestSkipped('Use phpunit-orders.xml');
        }
        $this->schema = 'rbac_test_' . bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA ' . $this->schema);
        DB::statement('SET search_path TO ' . $this->schema);
        (require database_path('migrations/2026_09_04_180000_create_rbac.php'))->up();
        (require database_path('migrations/2026_09_06_120000_add_order_management_permissions.php'))->up();
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->uuid('entity_uuid');
            $t->bigInteger('visible_order_id')->nullable();
            foreach (['order_id','client_order_id','paypal_order_id','customer_name','source_site','classification','influencer_name','receiving_paypal','currency','product_name','order_status','staff_code'] as $field) {
                $t->string($field)->nullable();
            }
            $t->timestampTz('order_time')->nullable();
            $t->decimal('amount_original', 14, 2)->nullable();
            $t->decimal('amount_usd', 14, 2)->nullable();
            $t->integer('items_count')->default(1);
            $t->integer('version')->default(1);
            $t->jsonb('raw')->nullable();
            $t->timestampsTz();
            $t->softDeletesTz();
        });
        Schema::create('invoice_orders', function (Blueprint $t) {
            $t->id();
            foreach (['order_number','customer_full_name','recipient_paypal','invoice_status'] as $f) {
                $t->string($f)->nullable();
            }
            $t->date('order_date')->nullable();
            $t->date('invoice_date');
            $t->decimal('amount_usd', 14, 2);
            $t->integer('version')->default(1);
            $t->jsonb('raw')->nullable();
            $t->timestampsTz();
            $t->softDeletesTz();
        });
        Schema::create('invoice_items', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('invoice_id');
            $t->string('product_name');
            $t->integer('quantity');
            $t->timestampsTz();
            $t->softDeletesTz();
        });
        Schema::create('invoice_staff_allocations', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('invoice_id');
            $t->string('staff_code');
            $t->decimal('share_ratio', 12, 10);
            $t->timestampsTz();
            $t->softDeletesTz();
        });
        Schema::create('exchange_rates', function (Blueprint $t) {
            $t->id();
            $t->string('currency');
            $t->decimal('rate_to_usd', 14, 8);
            $t->date('effective_date');
            $t->timestampsTz();
            $t->softDeletesTz();
        });
        Schema::create('order_user_overrides', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('order_uuid')->nullable();
            foreach (['order_key','order_key_type','status_override','primary_staff_code'] as $f) {
                $t->string($f)->nullable();
            } $t->timestampsTz();
            $t->softDeletesTz();
        });
        Schema::create('order_staff_allocations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('order_override_id');
            $t->string('staff_code');
            $t->decimal('share_ratio', 12, 10);
            $t->timestampsTz();
            $t->softDeletesTz();
        });
        $user = User::create(['username' => 'admin','display_name' => 'Admin','password_hash' => Hash::make('test'),'active' => 1,'role_id' => 1]);
        $this->withHeader('Authorization', 'Bearer ' . ApiToken::issue($user->id)['plain']);
    }

    protected function tearDown(): void
    {
        if (isset($this->schema) && preg_match('/^rbac_test_[a-f0-9]{16}$/', $this->schema)) {
            DB::statement('SET search_path TO public');
            DB::statement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
        parent::tearDown();
    }

    private function order(array $data = []): Order
    {
        return Order::create(array_merge(['order_id' => 'O-' . bin2hex(random_bytes(4)),'visible_order_id' => random_int(1, 1000000),'customer_name' => 'Buyer','classification' => 'offline','order_time' => '2026-08-01T02:00:00Z','amount_original' => 100,'amount_usd' => 100,'currency' => 'USD','items_count' => 2,'order_status' => 'Completed','staff_code' => 'AA','version' => 1], $data));
    }

    private function stat(string $module)
    {
        return $this->getJson('/api/order-management/statistics/' . $module . '?startDate=2026-08-01&endDate=2026-08-01');
    }

    public function test_totals_status_aliases_timezone_testing_and_soft_deletes(): void
    {
        $this->order(['order_time' => '2026-07-31T16:00:00Z','order_status' => 'Paid']);
        $this->order(['order_time' => '2026-08-01T15:59:59.999999Z']);
        $this->order(['order_time' => '2026-08-01T16:00:00Z']);
        $this->order(['customer_name' => 'Test Buyer']);
        $this->order(['order_status' => 'pending']);
        $this->order()->delete();
        $this->stat('overview')->assertOk()->assertJsonPath('data.orders', 2)->assertJsonPath('data.items', 4)->assertJsonPath('data.amountUsd', 200);
        $this->getJson('/api/order-management/orders?startDate=2026-08-01&endDate=2026-08-01&orderStatus=completed')->assertOk()->assertJsonPath('data.total', 2);
    }

    public function test_source_paypal_order_id_is_used_consistently_for_search_and_export(): void
    {
        $this->order([
            'client_order_id' => '7874',
            'order_id' => '260907225311404',
            'paypal_order_id' => ' <br/> ApplePay',
            'raw' => ['orderId' => '260907225311404'],
        ]);
        $this->getJson('/api/order-management/orders?orderId=7874')->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.orderId', '7874')
            ->assertJsonPath('data.list.0.paypalOrderId', '260907225311404');
        $this->getJson('/api/order-management/orders?paypalOrderId=260907225311404')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.orderId', '7874');
        $this->getJson('/api/order-management/orders?paypalOrderId=ApplePay')->assertOk()
            ->assertJsonPath('data.total', 0);
        $export = $this->get('/api/order-management/export?paypalOrderId=260907225311404');
        $export->assertOk();
        $this->assertStringContainsString('260907225311404', $export->streamedContent());
        $this->assertStringNotContainsString('ApplePay', $export->streamedContent());

        // 未携带旧系统快照的订单继续使用明确录入的 PayPal 编号。
        $this->order(['client_order_id' => 'MANUAL-1', 'paypal_order_id' => 'PAYPAL-123']);
        $this->getJson('/api/order-management/orders?paypalOrderId=PAYPAL-123')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.orderId', 'MANUAL-1');
    }

    public function test_invoice_deduplication_and_source_kpi_exclusion(): void
    {
        $this->order();
        $this->order(['classification' => 'invoice','client_order_id' => 'INV-A']);
        $id = DB::table('invoice_orders')->insertGetId(['order_number' => 'INV-A','invoice_date' => '2026-08-01','invoice_status' => 'Paid','amount_usd' => 50]);
        DB::table('invoice_items')->insert(['invoice_id' => $id,'product_name' => 'Item','quantity' => 3]);
        $this->stat('overview')->assertOk()->assertJsonPath('data.orders', 1);
        $this->stat('categories')->assertOk()->assertJsonPath('data.totals.orders', 2)->assertJsonPath('data.totals.amountUsd', 150);
        $this->getJson('/api/order-management/orders?classification=invoice')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.items', 3);
    }

    public function test_allocations_normalize_and_staff_drilldown_includes_collaborator(): void
    {
        $this->order(['raw' => ['staffAllocations' => [['staffCode' => 'AA','percent' => 25],['staffCode' => 'BB','percent' => 75]]]]);
        $this->stat('staff')->assertOk()->assertJsonPath('data.list.0.key', 'BB')->assertJsonPath('data.list.0.amountUsd', 75)->assertJsonPath('data.list.0.orders', 0.75);
        $this->getJson('/api/order-management/orders?customerService=bb')->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_currency_fallback_does_not_use_future_rates_or_lose_zero_values(): void
    {
        DB::table('exchange_rates')->insert([['currency' => 'EUR','effective_date' => '2026-07-01','rate_to_usd' => 1.2],['currency' => 'EUR','effective_date' => '2026-09-01','rate_to_usd' => 2]]);
        $this->order(['currency' => 'EUR','amount_usd' => null]);
        $this->order(['amount_usd' => 0]);
        $this->order(['currency' => 'XYZ','amount_usd' => null]);
        $this->stat('overview')->assertOk()->assertJsonPath('data.amountUsd', 120)->assertJsonPath('data.missingRates', 1);
    }

    public function test_independent_permissions_and_testing_are_enforced(): void
    {
        $role = Role::create(['name' => 'Reader','code' => 'reader','status' => 1]);
        $role->permissions()->sync(Permission::whereIn('code', ['system.order.statistics.overview'])->pluck('id'));
        $user = User::create(['username' => 'reader','display_name' => 'Reader','password_hash' => Hash::make('test'),'active' => 1,'role_id' => $role->id]);
        $this->withHeader('Authorization', 'Bearer ' . ApiToken::issue($user->id)['plain']);
        $this->stat('overview')->assertOk();
        $this->stat('staff')->assertForbidden();
        $this->getJson('/api/order-management/orders')->assertForbidden();
        $role->permissions()->sync(Permission::where('code', 'system.order.list')->pluck('id'));
        $this->getJson('/api/order-management/orders')->assertOk();
        $this->getJson('/api/order-management/orders?scope=testing')->assertForbidden();
        $this->getJson('/api/order-management/export')->assertForbidden();
    }

    public function test_adjustment_is_versioned_validated_and_updates_statistics(): void
    {
        $order = $this->order(['order_status' => 'pending']);
        $data = ['version' => 1,'targetStatus' => 'completed','primaryStaffCode' => 'AA','staffAllocations' => [['staffCode' => 'AA','percent' => 40],['staffCode' => 'BB','percent' => 60]]];
        $url = '/api/order-management/orders/' . $order->id . '/staff';
        $bad = $data;
        $bad['staffAllocations'][0]['percent'] = 10;
        $this->putJson($url, $bad)->assertUnprocessable();
        $this->assertSame(1, $order->fresh()->version);
        $this->putJson($url, $data)->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson($url, $data)->assertConflict();
        $this->stat('staff')->assertOk()->assertJsonPath('data.list.0.amountUsd', 60);
        $this->assertNotEmpty($order->fresh()->raw['dashboardEditHistory']);
    }

    public function test_validation_and_csv_export_protect_formula_cells(): void
    {
        $this->order(['customer_name' => '=HYPERLINK("x")']);
        $this->getJson('/api/order-management/statistics/overview?startDate=bad&endDate=2026-01-01')->assertUnprocessable();
        $this->getJson('/api/order-management/orders?startDate=2026-08-02&endDate=2026-08-01')->assertUnprocessable();
        $response = $this->get('/api/order-management/export');
        $response->assertOk();
        $this->assertStringContainsString("'=HYPERLINK", $response->streamedContent());
        $english = $this->get('/api/order-management/export?locale=en-US')->assertOk()->streamedContent();
        $this->assertSame(['Order ID', 'PayPal Order ID', 'Customer', 'Website', 'Status', 'Staff', 'PayPal', 'Amount', 'Currency', 'Date'], str_getcsv(explode("\r\n", substr($english, 3))[0], ',', '"', ''));
        $this->assertStringContainsString('订单ID', $response->streamedContent());
    }

    public function test_legacy_override_and_exact_drilldown(): void
    {
        $order = $this->order(['order_status' => 'pending','influencer_name' => 'Ann']);
        $this->order(['influencer_name' => 'Anna','staff_code' => 'AAB']);
        $override = (string)\Illuminate\Support\Str::uuid();
        DB::table('order_user_overrides')->insert(['id' => $override,'order_uuid' => $order->entity_uuid,'order_key' => $order->order_id,'order_key_type' => 'order','status_override' => 'completed','primary_staff_code' => 'AA']);
        DB::table('order_staff_allocations')->insert(['id' => (string)\Illuminate\Support\Str::uuid(),'order_override_id' => $override,'staff_code' => 'BB','share_ratio' => 1]);
        $this->stat('overview')->assertOk()->assertJsonPath('data.orders', 2);
        $this->getJson('/api/order-management/orders?influencer=Ann&influencerExact=1')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/order-management/orders?customerService=BB&staffExact=1')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/order-management/orders?customerService=AA&staffExact=1')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_permission_migration_is_idempotent_and_parent_is_home(): void
    {
        $before = Permission::count();
        (require database_path('migrations/2026_09_06_120000_add_order_management_permissions.php'))->up();
        $this->assertSame($before, Permission::count());
        $menu = Permission::where('code', 'dashboard.order_management')->firstOrFail();
        $this->assertSame(Permission::where('code', 'dashboard')->value('id'), $menu->parent_id);
        $this->assertSame(12, Permission::where('parent_id', $menu->id)->count());
    }

    public function test_primary_can_be_changed_and_must_remain_in_allocations(): void
    {
        $order = $this->order(['staff_code' => 'AA, BB']);
        $this->getJson('/api/order-management/orders')->assertOk()->assertJsonPath('data.list.0.primaryStaffCode', 'AA');
        $data = ['version' => 1,'primaryStaffCode' => 'BB','staffAllocations' => [['staffCode' => 'AA','percent' => 50],['staffCode' => 'BB','percent' => 50]]];
        $this->putJson('/api/order-management/orders/' . $order->id . '/staff', $data)->assertOk();
        $this->getJson('/api/order-management/orders')->assertOk()->assertJsonPath('data.list.0.primaryStaffCode', 'BB')->assertJsonPath('data.list.0.paymentStatus', 'completed');
        $this->assertSame('AA', $order->fresh()->raw['dashboardEditHistory'][0]['before']['primaryStaffCode']);
        $data['version'] = 2;
        $data['staffAllocations'] = [['staffCode' => 'AA', 'percent' => 100]];
        $this->putJson('/api/order-management/orders/' . $order->id . '/staff', $data)->assertUnprocessable();
        $this->assertSame(2, $order->fresh()->version);
    }

    public function test_replacing_primary_removes_old_shares_from_orders_and_statistics(): void
    {
        $order = $this->order([
            'raw' => ['staffAllocations' => [
                ['staffCode' => 'AA', 'percent' => 40],
                ['staffCode' => 'BB', 'percent' => 60],
            ]],
        ]);
        $url = '/api/order-management/orders/' . $order->id . '/staff';
        $allocations = [
            ['staffCode' => 'CC', 'percent' => 40],
            ['staffCode' => 'BB', 'percent' => 60],
        ];
        $this->putJson($url, [
            'version' => 1,
            'primaryStaffCode' => 'CC',
            'staffAllocations' => $allocations,
        ])->assertOk()->assertJsonPath('data.version', 2);

        $this->assertEquals($allocations, $order->fresh()->raw['staffAllocations']);
        $this->getJson('/api/order-management/orders')->assertOk()
            ->assertJsonPath('data.list.0.primaryStaffCode', 'CC')
            ->assertJsonPath('data.list.0.paymentStatus', 'completed')
            ->assertJsonCount(2, 'data.list.0.staffAllocations');
        $this->getJson('/api/order-management/orders?customerService=AA&staffExact=1')
            ->assertOk()->assertJsonPath('data.total', 0);
        $this->stat('staff')->assertOk()->assertJsonCount(2, 'data.list')
            ->assertJsonPath('data.list.0.key', 'BB')->assertJsonPath('data.list.0.amountUsd', 60)
            ->assertJsonPath('data.list.1.key', 'CC')->assertJsonPath('data.list.1.amountUsd', 40);
        $this->assertSame('AA', $order->fresh()->raw['dashboardEditHistory'][0]['before']['primaryStaffCode']);

        // 再把已有协同客服设为主客服，合并后仅保留一份分成。
        $this->putJson($url, [
            'version' => 2,
            'primaryStaffCode' => 'BB',
            'staffAllocations' => [['staffCode' => 'BB', 'percent' => 100]],
        ])->assertOk()->assertJsonPath('data.version', 3);
        $this->stat('staff')->assertOk()->assertJsonCount(1, 'data.list')
            ->assertJsonPath('data.list.0.key', 'BB')->assertJsonPath('data.list.0.amountUsd', 100);
    }

    public function test_editor_options_include_history_and_require_order_edit_permissions(): void
    {
        User::where('id', 1)->update(['staff_code' => 'CT']);
        $this->order(['staff_code' => 'AA, BB', 'raw' => ['staffAllocations' => [['staffCode' => 'cc', 'percent' => 100]]]]);
        $deleted = $this->order(['staff_code' => 'DELETED']);
        $deleted->delete();
        $this->getJson('/api/order-management/editor-options')->assertOk()->assertJsonPath('data.staff', ['AA', 'BB', 'CC', 'CT']);

        $role = Role::create(['name' => 'Order reader', 'code' => 'order_reader', 'status' => 1]);
        $role->permissions()->sync(Permission::where('code', 'system.order.list')->pluck('id'));
        $user = User::create(['username' => 'order_reader', 'display_name' => 'Reader', 'password_hash' => Hash::make('test'), 'active' => 1, 'role_id' => $role->id]);
        $this->withHeader('Authorization', 'Bearer ' . ApiToken::issue($user->id)['plain']);
        $this->getJson('/api/order-management/editor-options')->assertForbidden();
        $role->permissions()->attach(Permission::where('code', 'system.order.update')->value('id'));
        $this->getJson('/api/order-management/editor-options')->assertOk();
    }

    public function test_editing_uses_effective_status_and_preserves_failed_or_completed_orders(): void
    {
        $order = $this->order(['order_status' => 'pending']);
        DB::table('order_user_overrides')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'order_uuid' => $order->entity_uuid,
            'order_key' => $order->order_id,
            'order_key_type' => 'order',
            'status_override' => 'completed',
            'primary_staff_code' => 'AA',
        ]);
        $data = ['version' => 1, 'primaryStaffCode' => 'BB', 'staffAllocations' => [['staffCode' => 'BB', 'percent' => 100]]];
        $url = '/api/order-management/orders/' . $order->id . '/staff';
        $this->putJson($url, $data + ['targetStatus' => 'completed'])->assertUnprocessable();
        $this->putJson($url, $data)->assertOk();
        $this->assertSame('completed', $order->fresh()->order_status);

        $failed = $this->order(['order_status' => 'failed']);
        $this->putJson('/api/order-management/orders/' . $failed->id . '/staff', $data)->assertOk();
        $this->assertSame('failed', $failed->fresh()->order_status);
        $this->assertSame('BB', $failed->fresh()->staff_code);
    }
}
