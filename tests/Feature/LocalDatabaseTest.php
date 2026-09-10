<?php

namespace Tests\Feature;

use App\Models\SiteClassificationReclassification;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** 使用临时 schema 验证真实初始化路径，绝不清空 public。 */
class LocalDatabaseTest extends TestCase
{
    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RBAC_TEST_POSTGRES') !== '1') {
            $this->markTestSkipped('Use phpunit-local.xml with PostgreSQL.');
        }
        $this->schema = 'rbac_test_' . bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA ' . $this->schema);
        DB::statement('SET search_path TO ' . $this->schema);
        $this->app['env'] = 'local';
        config(['rbac.seed_admin_password' => 'Local-Test-123']);
        $this->assertSame(0, Artisan::call('local:database-init'), Artisan::output());
    }

    protected function tearDown(): void
    {
        if (isset($this->schema) && preg_match('/^rbac_test_[a-f0-9]{16}$/', $this->schema)) {
            DB::statement('SET search_path TO public');
            DB::statement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
        parent::tearDown();
    }

    public function test_baseline_covers_documented_columns_and_refuses_reinitialization(): void
    {
        $manifest = json_decode(file_get_contents(database_path('schema/newsql-manifest.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(45, $manifest);
        foreach ($manifest as $table => $definitions) {
            $this->assertTrue(Schema::hasTable($table), $table);
            foreach ($definitions as $definition) {
                if (!str_starts_with($definition, 'CONSTRAINT ')) {
                    $column = preg_split('/\s+/', $definition)[0];
                    $this->assertTrue(Schema::hasColumn($table, $column), $table . '.' . $column);
                }
            }
        }
        $foreignKeys = DB::table('information_schema.table_constraints')
            ->where('constraint_schema', $this->schema)->where('constraint_type', 'FOREIGN KEY')->count();
        $this->assertSame(48, $foreignKeys);
        $this->assertSame(1, Artisan::call('local:database-init'));
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_permissions_cover_routes_and_reseeding_preserves_existing_grants(): void
    {
        $permissions = DB::table('permissions')->orderBy('id')->get()->keyBy('code');
        // 权限目录会随功能增加；验证必需入口及下方路由覆盖，不固定历史总数。
        $this->assertSame('menu', $permissions['dashboard.collector']->type);
        $this->assertSame($permissions['system']->id, $permissions['dashboard.collector']->parent_id);
        $this->assertSame('/system/collector', $permissions['dashboard.collector']->path);
        $this->assertSame('menu', $permissions['inspection']->type);
        $this->assertSame($permissions['business']->id, $permissions['dashboard.order_management']->parent_id);
        $this->assertSame('/workbench/order-management', $permissions['dashboard.order_management']->path);
        $this->assertSame('workbench/order-management/index', $permissions['dashboard.order_management']->component);
        $this->assertSame(['dashboard.overview'], $permissions->where('parent_id', $permissions['dashboard']->id)->where('type', 'menu')->keys()->values()->all());
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (str_starts_with($middleware, 'permission:')) {
                    foreach (preg_split('/[,|]/', substr($middleware, 11)) as $code) {
                        $this->assertTrue($permissions->has($code), $route->uri() . ': ' . $code);
                    }
                }
            }
        }
        foreach ($permissions as $permission) {
            $this->assertNotEmpty($permission->name_zh);
            $this->assertDoesNotMatchRegularExpression('/[\x{4e00}-\x{9fff}]/u', $permission->name);
        }

        $viewerId = DB::table('roles')->where('code', 'viewer')->value('id');
        DB::table('role_permissions')->where('role_id', $viewerId)->delete();
        DB::table('role_permissions')->insert([
            'role_id' => $viewerId,
            'permission_id' => $permissions['business.invoice.list']->id,
        ]);
        DB::table('permissions')->where('code', 'business.warehouse.update')->update(['status' => 0]);
        DB::table('users')->where('username', 'super_admin')->update(['active' => false]);
        $before = DB::table('users')->where('username', 'super_admin')->first();
        $this->seed(RbacSeeder::class);
        $this->seed(RbacSeeder::class);

        $this->assertSame($permissions->pluck('id', 'code')->all(), DB::table('permissions')->orderBy('id')->pluck('id', 'code')->all());
        $this->assertSame([$permissions['business.invoice.list']->id], DB::table('role_permissions')->where('role_id', $viewerId)->pluck('permission_id')->all());
        $this->assertSame(0, DB::table('permissions')->where('code', 'business.warehouse.update')->value('status'));
        $after = DB::table('users')->where('username', 'super_admin')->first();
        $this->assertSame($before->password_hash, $after->password_hash);
        $this->assertFalse($after->active);
    }

    public function test_menu_migration_preserves_order_grants_and_uses_business_ancestors(): void
    {
        $permissions = DB::table('permissions')->get()->keyBy('code');
        $orderMenu = $permissions['dashboard.order_management'];
        DB::table('permissions')->where('id', $orderMenu->id)->update([
            'parent_id' => $permissions['dashboard']->id,
            'path' => '/dashboard/order-management',
            'component' => 'dashboard/order-management/index',
        ]);
        $viewerId = DB::table('roles')->where('code', 'viewer')->value('id');
        DB::table('role_permissions')->where('role_id', $viewerId)->delete();
        DB::table('role_permissions')->insert(['role_id' => $viewerId, 'permission_id' => $permissions['system.order.list']->id]);
        $before = DB::table('role_permissions')->orderBy('id')->get()->toJson();

        $migration = require database_path('migrations/2026_09_07_180000_move_order_management_to_business.php');
        $migration->up();
        $migration->up();

        $this->assertSame($before, DB::table('role_permissions')->orderBy('id')->get()->toJson());
        $this->assertSame($orderMenu->id, DB::table('permissions')->where('code', 'dashboard.order_management')->value('id'));
        $user = \App\Models\User::create(['username' => 'orders-viewer', 'display_name' => 'Orders Viewer', 'password_hash' => 'unused', 'active' => true, 'role_id' => $viewerId]);
        $codes = app(\App\Services\RbacService::class)->codes($user);
        $this->assertContains('business', $codes);
        $this->assertContains('dashboard.order_management', $codes);
        $this->assertContains('system.order.list', $codes);
        $this->assertNotContains('dashboard', $codes);
        $this->assertNotContains('system.order.update', $codes);
    }

    public function test_composite_key_updates_and_deletes_only_the_selected_order(): void
    {
        $attributes = [
            'release_id' => 'release-test',
            'source_domain' => 'example.test',
            'previous_classification' => 'online',
            'target_classification' => 'offline',
        ];
        $first = SiteClassificationReclassification::create($attributes + ['order_id' => 'order-1']);
        $second = SiteClassificationReclassification::create($attributes + ['order_id' => 'order-2']);
        $second->update(['target_classification' => 'influencer']);
        $this->assertSame('offline', $first->fresh()->target_classification);
        $this->assertSame('influencer', $second->fresh()->target_classification);
        $second->delete();
        $this->assertSame(1, SiteClassificationReclassification::query()->count());
        $this->assertSame('order-1', SiteClassificationReclassification::query()->first()->order_id);
    }
}
