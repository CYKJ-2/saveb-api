<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Integration tests run against a fresh, disposable PostgreSQL schema only. */
class RbacTest extends TestCase
{
    private string $schema;

    private User $admin;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RBAC_TEST_POSTGRES') !== '1') {
            $this->markTestSkipped('Use phpunit-rbac.xml for isolated PostgreSQL integration tests.');
        }
        $this->schema = 'rbac_test_' . bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA ' . $this->schema);
        DB::statement('SET search_path TO ' . $this->schema);
        (require database_path('migrations/2026_09_04_180000_create_rbac.php'))->up();
        $this->admin = $this->user('root', 1);
        $this->adminToken = $this->token($this->admin);
    }

    protected function tearDown(): void
    {
        if (isset($this->schema) && preg_match('/^rbac_test_[a-f0-9]{16}$/', $this->schema)) {
            DB::statement('SET search_path TO public');
            DB::statement('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
        parent::tearDown();
    }

    private function user(string $name, ?int $role = null): User
    {
        return User::create(['username' => $name, 'display_name' => $name, 'password_hash' => Hash::make('Test-password-123'), 'active' => 1, 'role_id' => $role]);
    }

    private function token(User $user): string
    {
        return ApiToken::issue($user->id)['plain'];
    }

    private function role(string $code = 'operator'): Role
    {
        return Role::create(['code' => $code, 'name' => $code, 'status' => 1]);
    }

    private function permission(string $code, int $parent = 0, string $type = 'action'): Permission
    {
        return Permission::create(['code' => $code, 'name' => $code, 'parent_id' => $parent, 'type' => $type, 'status' => 1]);
    }

    private function asToken(string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    public function test_primary_role_login_me_and_api_agree_and_disabled_role_revokes_existing_token(): void
    {
        $menu = $this->permission('system.user', 0, 'menu');
        $read = $this->permission('system.user.list', $menu->id);
        $role = $this->role();
        $role->permissions()->sync([$read->id]);
        $user = $this->user('reader', $role->id);
        $login = $this->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'Test-password-123'])->assertOk();
        $token = $login->json('data.token');
        $this->assertSame('system.user', $login->json('data.permissions.0.code'));
        $this->asToken($token)->getJson('/api/users')->assertOk();
        $this->getJson('/api/auth/me')->assertJsonPath('data.permissions.0.children.0.code', 'system.user.list');
        $role->update(['status' => 0]);
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/auth/me')->assertJsonPath('data.user.role_codes', [])->assertJsonPath('data.permissions', []);
    }

    public function test_multiple_roles_union_and_empty_assignment_revoke_primary_and_pivot(): void
    {
        $p = $this->permission('system.user.list');
        $r = $this->role();
        $r->permissions()->sync([$p->id]);
        $user = $this->user('target', $r->id);
        $user->roles()->sync([$r->id]);
        $token = $this->token($user);
        $this->asToken($token)->getJson('/api/users')->assertOk();
        $this->asToken($this->adminToken)->putJson('/api/users/' . $user->id . '/roles', ['role_ids' => []])->assertOk();
        $this->assertNull($user->fresh()->role_id);
        $this->assertSame(0, $user->roles()->count());
        $this->asToken($token)->getJson('/api/users')->assertForbidden();
        $this->asToken($this->adminToken)->putJson('/api/users/' . $user->id . '/roles', ['role_ids' => [$r->id]])->assertOk();
        $this->assertSame($this->admin->id, (int) $user->roles()->first()->pivot->granted_by_user_id);
        $this->asToken($token)->getJson('/api/users')->assertOk();
    }

    public function test_ordinary_user_cannot_reset_password_or_embed_role_assignment(): void
    {
        $r = $this->role();
        $p = $this->permission('system.user.update');
        $r->permissions()->sync([$p->id]);
        $user = $this->user('editor', $r->id);
        $plain = $this->user('ordinary');
        $this->asToken($this->token($plain))->postJson('/api/users/' . $user->id . '/password', ['password' => 'Changed-123'])->assertForbidden();
        $this->asToken($this->token($user))->putJson('/api/users/' . $plain->id, ['role_ids' => [1]])->assertForbidden();
        $this->postJson('/api/users/' . $this->admin->id . '/password', ['password' => 'Changed-123'])->assertForbidden();
        $this->assertTrue(Hash::check('Test-password-123', $this->admin->fresh()->password_hash));
    }

    public function test_super_admin_without_pivot_sees_new_permissions_and_cannot_delete_self(): void
    {
        $this->permission('new.menu', 0, 'menu');
        $this->asToken($this->adminToken)->getJson('/api/auth/me')->assertJsonPath('data.permissions.0.code', 'new.menu');
        $this->deleteJson('/api/users/' . $this->admin->id)->assertStatus(422);
    }

    public function test_assignment_is_exact_validated_and_empty_array_supported(): void
    {
        $menu = $this->permission('system.user', 0, 'menu');
        $read = $this->permission('system.user.list', $menu->id);
        $write = $this->permission('system.user.delete', $menu->id);
        $role = $this->role();
        $url = '/api/roles/' . $role->id . '/permissions';
        $this->asToken($this->adminToken)->putJson($url, ['permission_ids' => [$menu->id, $read->id]])->assertOk();
        $this->assertEqualsCanonicalizing([$menu->id, $read->id], $role->permissions()->pluck('permissions.id')->all());
        $this->putJson($url, ['permission_ids' => [999999]])->assertStatus(422);
        $this->putJson($url, [])->assertStatus(422);
        $this->assertSame(2, $role->permissions()->count());
        $this->putJson($url, ['permission_ids' => []])->assertOk();
        $this->assertSame(0, $role->permissions()->count());
    }

    public function test_disabled_or_deleted_ancestor_revokes_child_permission(): void
    {
        $menu = $this->permission('system', 0, 'menu');
        $p = $this->permission('system.user.list', $menu->id);
        $r = $this->role();
        $r->permissions()->sync([$p->id]);
        $u = $this->user('reader', $r->id);
        $this->asToken($this->token($u))->getJson('/api/users')->assertOk();
        $menu->update(['status' => 0]);
        $this->getJson('/api/users')->assertForbidden();
        $menu->update(['status' => 1]);
        $menu->delete();
        $this->getJson('/api/users')->assertForbidden();
    }

    public function test_role_with_pivot_user_cannot_be_deleted_and_password_reset_revokes_tokens(): void
    {
        $r = $this->role();
        $u = $this->user('target');
        $u->roles()->sync([$r->id]);
        $token = $this->token($u);
        $this->asToken($this->adminToken)->deleteJson('/api/roles/' . $r->id)->assertStatus(422);
        $this->postJson('/api/users/' . $u->id . '/password', ['password' => 'Changed-123'])->assertOk();
        $this->asToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_assignment_cannot_escalate_and_invalid_create_is_atomic(): void
    {
        $r = $this->role();
        $p = $this->permission('system.user.assign_role');
        $create = $this->permission('system.user.create');
        $r->permissions()->sync([$p->id, $create->id]);
        $u = $this->user('manager', $r->id);
        $target = $this->user('target');
        $this->asToken($this->token($u))->putJson('/api/users/' . $target->id . '/roles', ['role_ids' => [1]])->assertForbidden();
        $this->postJson('/api/users', ['username' => 'escalation', 'password' => 'Test-123', 'role_ids' => [1]])->assertForbidden();
        $this->assertFalse(User::where('username', 'escalation')->exists());
        $this->getJson('/api/roles/all')->assertOk();
    }

    public function test_permission_tree_rejects_cycles_and_soft_deletes_entire_subtree(): void
    {
        $parent = $this->permission('system', 0, 'menu');
        $child = $this->permission('system.user', $parent->id, 'menu');
        $action = $this->permission('system.user.list', $child->id);
        $this->asToken($this->adminToken)->putJson('/api/permissions/' . $parent->id, ['parent_id' => $child->id])->assertStatus(422);
        $this->postJson('/api/permissions', ['code' => 'orphan', 'name' => 'orphan', 'parent_id' => 999999])->assertStatus(422);
        $this->deleteJson('/api/permissions/' . $parent->id)->assertOk();
        $this->assertTrue($child->fresh()->trashed());
        $this->assertNull(Permission::find($action->id));
    }

    public function test_user_filters_include_disabled_users_and_preserve_total(): void
    {
        $this->user('enabled');
        $disabled = $this->user('disabled');
        $disabled->update(['active' => 0]);
        $this->asToken($this->adminToken)->getJson('/api/users?active=false&username=disabled')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.username', 'disabled');
        $this->getJson('/api/users?per_page=1')->assertOk()->assertJsonPath('total', 3)->assertJsonCount(1, 'data');
    }

    public function test_multi_role_union_removal_and_disabled_super_admin(): void
    {
        $read = $this->permission('system.user.list');
        $rolesRead = $this->permission('system.role.list');
        $a = $this->role('a');
        $b = $this->role('b');
        $a->permissions()->sync([$read->id]);
        $b->permissions()->sync([$rolesRead->id]);
        $u = $this->user('multi', $a->id);
        $u->roles()->sync([$a->id, $b->id]);
        $token = $this->token($u);
        $this->asToken($token)->getJson('/api/users')->assertOk();
        $this->getJson('/api/roles')->assertOk();
        $this->asToken($this->adminToken)->deleteJson('/api/users/' . $u->id . '/roles/' . $a->id)->assertOk();
        $this->asToken($token)->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/roles')->assertOk();
        $this->asToken($this->adminToken)->deleteJson('/api/users/' . $u->id . '/role')->assertOk()->assertJsonPath('data.role', null);
        $this->asToken($token)->getJson('/api/roles')->assertForbidden();
        Role::find(1)->update(['status' => 0]);
        $this->asToken($this->adminToken)->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/auth/me')->assertJsonPath('data.user.role_codes', []);
    }

    public function test_logout_invalidates_current_token_and_expired_token_is_rejected(): void
    {
        $u = $this->user('session');
        $token = $this->token($u);
        $this->asToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $token = $this->token($u);
        $u->apiTokens()->update(['expires_at' => now()->subMinute()]);
        $this->asToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_self_lockout_and_wildcard_permission_are_rejected(): void
    {
        $this->asToken($this->adminToken)->putJson('/api/users/' . $this->admin->id, ['active' => false])->assertStatus(422);
        $this->putJson('/api/users/' . $this->admin->id . '/roles', ['role_ids' => []])->assertStatus(422);
        $this->postJson('/api/permissions', ['code' => '*', 'name' => 'wildcard'])->assertStatus(422);
        $this->assertSame(1, $this->admin->fresh()->active);
    }

    public function test_non_super_manager_cannot_reset_higher_privilege_account(): void
    {
        $write = $this->permission('system.user.update');
        $readRoles = $this->permission('system.role.list');
        $lower = $this->role('lower');
        $higher = $this->role('higher');
        $lower->permissions()->sync([$write->id]);
        $higher->permissions()->sync([$write->id, $readRoles->id]);
        $manager = $this->user('manager', $lower->id);
        $target = $this->user('target', $higher->id);
        $this->asToken($this->token($manager))->postJson('/api/users/' . $target->id . '/password', ['password' => 'Changed-123'])->assertForbidden();
    }
}
