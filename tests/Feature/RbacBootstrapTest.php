<?php

namespace Tests\Feature;

use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/** 模拟他人 git clone 后首次安装：只使用源码和配置，不读取本地快照。 */
class RbacBootstrapTest extends TestCase
{
    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RBAC_TEST_POSTGRES') !== '1') {
            $this->markTestSkipped('Use phpunit-local.xml for isolated PostgreSQL tests.');
        }
        $this->schema = 'rbac_test_' . bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA ' . $this->schema);
        DB::statement('SET search_path TO ' . $this->schema);
        $this->app['env'] = 'production';
    }

    protected function tearDown(): void
    {
        if (isset($this->schema) && preg_match('/^rbac_test_[a-f0-9]{16}$/', $this->schema)) {
            DB::statement('SET search_path TO public');
            DB::statement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
        parent::tearDown();
    }

    private function initialize(): int
    {
        return Artisan::call('server:database-init', ['--force' => true]);
    }

    private function tableCount(): int
    {
        return DB::table('information_schema.tables')->where('table_schema', $this->schema)->count();
    }

    public function test_fresh_install_creates_all_tables_permissions_and_working_administrator(): void
    {
        $this->assertSame(0, $this->initialize(), Artisan::output());
        $manifest = json_decode(file_get_contents(database_path('schema/newsql-manifest.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach (array_keys($manifest) as $table) {
            $this->assertTrue(DB::getSchemaBuilder()->hasTable($table), $table);
        }
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('business_operation_logs'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('online_spreadsheets'));
        $this->assertSame(1, DB::table('users')->count());
        $admin = DB::table('users')->where('username', 'super_admin')->sole();
        $this->assertTrue(Hash::check('123456', $admin->password_hash));
        $this->assertTrue((bool) $admin->must_change_password);
        $this->assertSame(3, DB::table('roles')->count());
        $this->assertSame(99, DB::table('permissions')->count());
        $this->assertSame(99, DB::table('role_permissions')->where('role_id', $admin->role_id)->count());
        $this->assertSame('采购部', DB::table('permissions')->where('code', 'business.procurement')->value('name_zh'));
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('invoice_orders')->count());
        $this->postJson('/api/auth/login', ['username' => 'super_admin', 'password' => '123456'])->assertOk();
        $viewer = DB::table('roles')->where('code', 'viewer')->value('id');
        $codes = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', $viewer)->pluck('p.code')->all();
        $this->assertContains('dashboard.overview', $codes);
        $this->assertNotContains('dashboard.overview.collector_trigger', $codes);
        $this->assertNotContains('system.order.delete', $codes);
        $this->assertNotContains('business.paypal.withdrawal', $codes);
        foreach (['users', 'roles', 'permissions', 'role_permissions', 'user_roles'] as $table) {
            $this->assertGreaterThan(DB::table($table)->max('id'), DB::selectOne("SELECT nextval(pg_get_serial_sequence(?, 'id')) AS id", [$table])->id);
        }
    }

    public function test_existing_database_is_never_overwritten_by_install(): void
    {
        $this->assertSame(0, $this->initialize(), Artisan::output());
        DB::table('users')->where('username', 'super_admin')->update(['display_name' => '服务器修改']);
        $this->assertSame(1, $this->initialize());
        $this->assertSame('服务器修改', DB::table('users')->where('username', 'super_admin')->value('display_name'));
    }

    public function test_failed_seeding_rolls_back_structure_and_migration_records(): void
    {
        $this->app->bind(RbacSeeder::class, fn () => new class () extends RbacSeeder {
            public function run(): void
            {
                throw new RuntimeException('Injected seed failure');
            }
        });
        $this->assertSame(1, $this->initialize());
        $this->assertSame(0, $this->tableCount());
    }

    public function test_reseeding_keeps_password_account_status_and_custom_role_grants(): void
    {
        $this->assertSame(0, $this->initialize(), Artisan::output());
        $before = DB::table('users')->where('username', 'super_admin')->first();
        $changedPassword = Hash::make('User-Changed-Password-456');
        DB::table('users')->where('id', $before->id)->update(['active' => false, 'password_hash' => $changedPassword]);
        $viewer = DB::table('roles')->where('code', 'viewer')->value('id');
        DB::table('role_permissions')->where('role_id', $viewer)->delete();
        $permission = DB::table('permissions')->where('code', 'business.invoice.list')->value('id');
        DB::table('role_permissions')->insert(['role_id' => $viewer, 'permission_id' => $permission]);
        $this->seed(RbacSeeder::class);
        $this->assertSame($changedPassword, DB::table('users')->where('id', $before->id)->value('password_hash'));
        $this->assertFalse(Hash::check('123456', $changedPassword));
        $this->assertFalse((bool) DB::table('users')->where('id', $before->id)->value('active'));
        $this->assertSame([$permission], DB::table('role_permissions')->where('role_id', $viewer)->pluck('permission_id')->all());
    }

    public function test_force_is_required_for_installation(): void
    {
        $this->assertSame(1, Artisan::call('server:database-init'));
        $this->assertSame(0, $this->tableCount());
    }
}
