<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CollectorTest extends TestCase
{
    public function test_jobs_and_chunks_default_to_twenty_and_support_page_size(): void
    {
        $id = $this->job();
        for ($index = 1; $index <= 25; $index++) {
            if ($index > 1) {
                $this->job();
            }
            DB::table('chunks')->insert(['job_id' => $id, 'status' => 'succeeded', 'scope' => '{}', 'counts' => '{}', 'raw' => '{"secret":"must-not-leak"}']);
        }
        $this->getJson('/api/collector/jobs')->assertOk()->assertJsonCount(20, 'data.items')->assertJsonPath('data.total', 25);
        $this->getJson('/api/collector/jobs?page=2')->assertOk()->assertJsonCount(5, 'data.items');
        $this->getJson('/api/collector/jobs?per_page=50')->assertOk()->assertJsonCount(25, 'data.items');
        $this->getJson('/api/collector/jobs/' . $id)->assertOk()->assertJsonCount(20, 'data.chunks.items')->assertDontSee('must-not-leak');
        $this->getJson('/api/collector/jobs/' . $id . '?page=2')->assertOk()->assertJsonCount(5, 'data.chunks.items');
        $this->getJson('/api/collector/jobs/' . $id . '?per_page=50')->assertOk()->assertJsonCount(25, 'data.chunks.items');
        $this->getJson('/api/collector/jobs?per_page=101')->assertUnprocessable();
    }

    private string $schema;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RBAC_TEST_POSTGRES') !== '1') {
            $this->markTestSkipped('Use phpunit-collector.xml');
        }
        $this->schema = 'rbac_test_' . bin2hex(random_bytes(8));
        DB::statement('CREATE SCHEMA ' . $this->schema);
        DB::statement('SET search_path TO ' . $this->schema);
        (require database_path('migrations/2026_09_04_180000_create_rbac.php'))->up();
        (require database_path('migrations/2026_09_06_180000_add_dashboard_overview_permissions.php'))->up();
        (require database_path('migrations/2026_09_07_230000_add_collector_permissions.php'))->up();
        (require database_path('migrations/2026_09_07_235000_add_collector_management_permissions.php'))->up();
        DB::statement('CREATE TABLE jobs (id text primary key,account text,mode text,actor text,status text,error text,params jsonb,context jsonb,created_at timestamptz,updated_at timestamptz)');
        DB::statement('CREATE TABLE scheduler_state (account text primary key,last_attempt_at timestamptz,last_success_at timestamptz,error text)');
        DB::statement('CREATE TABLE schedules (account text primary key,interval_minutes integer not null default 30,next_run_at timestamptz not null default now(),updated_by text not null default \'system\',updated_at timestamptz not null default now())');
        DB::statement('CREATE TABLE chunks (id bigserial primary key,job_id text,scope jsonb,status text,attempts int,error text,counts jsonb,committed_at timestamptz,raw jsonb)');
        config(['collector.schema' => $this->schema, 'collector.url' => 'http://collector.test', 'collector.token' => 'server-only-test-token', 'collector.account' => 'default']);
        $user = User::create(['username' => 'admin','display_name' => 'Admin','password_hash' => Hash::make('Test-123'),'active' => 1,'role_id' => 1]);
        $this->userId = $user->id;
        $this->withHeader('Authorization', 'Bearer ' . ApiToken::issue($user->id)['plain']);
        $this->travelTo(new \DateTimeImmutable('2026-09-06T16:10:00Z'));
        Http::preventStrayRequests();
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

    private function job(array $values = []): string
    {
        $id = (string) Str::uuid();
        DB::table('jobs')->insert([...[
            'id' => $id, 'account' => 'default', 'mode' => 'today', 'actor' => 'saveb-api:' . $this->userId,
            'status' => 'succeeded', 'params' => json_encode(['dry_run' => false]),
            'context' => json_encode(['publish_api' => true]), 'created_at' => now()->subMinutes(5), 'updated_at' => now(),
        ], ...$values]);

        return $id;
    }

    private function heartbeat(): void
    {
        DB::table('scheduler_state')->insert(['account' => 'default', 'last_attempt_at' => now(), 'last_success_at' => now()]);
    }

    public function test_status_uses_committed_jobs_and_scheduler_heartbeat(): void
    {
        $this->job();
        $this->getJson('/api/collector/status')->assertOk()->assertJsonPath('data.state', 'stale')->assertJsonPath('data.schedulerHealthy', false);
        $this->heartbeat();
        $this->getJson('/api/collector/status')->assertOk()->assertJsonPath('data.state', 'success')->assertJsonPath('data.currentDate', '2026-09-07')->assertJsonPath('data.nextAttemptAt', '2026-09-07T00:30:00+08:00');
        $this->travel(31)->minutes();
        $this->getJson('/api/collector/status')->assertJsonPath('data.state', 'stale');
    }

    public function test_preview_history_and_other_account_cannot_mask_failure(): void
    {
        $this->job(['status' => 'failed', 'error' => 'AUTH_EXPIRED']);
        $this->job(['mode' => 'history']);
        $this->job(['account' => 'another']);
        $this->job(['params' => '{"dry_run":true}']);
        $this->getJson('/api/collector/status')->assertJsonPath('data.state', 'failed')->assertJsonPath('data.lastSuccessAt', null);
    }

    public function test_queue_stall_is_visible(): void
    {
        $this->job(['status' => 'queued']);
        $this->getJson('/api/collector/status')->assertJsonPath('data.state', 'running');
        $this->travel(36)->minutes();
        $this->getJson('/api/collector/status')->assertJsonPath('data.state', 'stale');
    }

    public function test_long_running_collection_with_recent_commits_is_not_stalled(): void
    {
        $id = $this->job(['status' => 'running', 'created_at' => now()->subHours(2)]);
        DB::table('chunks')->insert([
            ['job_id' => $id, 'status' => 'succeeded', 'committed_at' => now()->subMinute()],
            ['job_id' => $id, 'status' => 'queued', 'committed_at' => null],
        ]);
        $this->getJson('/api/collector/status')->assertOk()->assertJsonPath('data.state', 'running')
            ->assertJsonPath('data.completedChunks', 1)->assertJsonPath('data.totalChunks', 2)
            ->assertJsonPath('data.lastError', null);
        $this->travel(36)->minutes();
        $this->getJson('/api/collector/status')->assertJsonPath('data.state', 'stale');
    }

    public function test_manual_today_sends_trusted_actor_and_checks_same_database(): void
    {
        $id = $this->job(['status' => 'queued']);
        Http::fake(['collector.test/*' => Http::response(['jobId' => $id], 202)]);
        $requestId = (string) Str::uuid();
        $this->postJson('/api/collector/today', ['requestId' => $requestId])->assertStatus(202)->assertJsonPath('data.jobId', $id);
        Http::assertSent(fn ($request) => $request['mode'] === 'today' && $request->hasHeader('Idempotency-Key', $requestId) && $request->hasHeader('X-Collector-Actor', 'saveb-api:' . $this->userId) && $request->hasHeader('Authorization', 'Bearer server-only-test-token') && count($request->data()) === 1);
    }

    public function test_shadow_wrong_database_invalid_request_and_unavailable_service(): void
    {
        $this->postJson('/api/collector/today', ['requestId' => 'bad'])->assertUnprocessable();
        Http::fake(['collector.test/*' => Http::response(['jobId' => 'unknown'], 202)]);
        $this->postJson('/api/collector/today', ['requestId' => (string) Str::uuid()])->assertStatus(503);
        config(['collector.token' => '']);
        $this->postJson('/api/collector/today', ['requestId' => (string) Str::uuid()])->assertStatus(503);
    }

    public function test_read_and_trigger_require_independent_permissions(): void
    {
        $user = User::create(['username' => 'viewer','display_name' => 'Viewer','password_hash' => Hash::make('Test-123'),'active' => 1,'role_id' => 2]);
        DB::table('role_permissions')->where('role_id', 2)->delete();
        $permission = Permission::where('code', 'dashboard.overview.collector_status')->firstOrFail();
        DB::table('role_permissions')->insert(['role_id' => 2, 'permission_id' => $permission->id]);
        $this->withHeader('Authorization', 'Bearer ' . ApiToken::issue($user->id)['plain']);
        $this->getJson('/api/collector/status')->assertOk();
        $this->postJson('/api/collector/today', ['requestId' => (string) Str::uuid()])->assertForbidden();
        $this->withHeader('Authorization', 'Bearer invalid');
        $this->getJson('/api/collector/status')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_schedule_can_be_changed_without_touching_business_data(): void
    {
        $this->putJson('/api/collector/settings', ['intervalMinutes' => 75])->assertOk()->assertJsonPath('data.intervalMinutes', 75);
        $this->assertSame('saveb-api:' . $this->userId, DB::table('schedules')->value('updated_by'));
        $this->getJson('/api/collector/settings')->assertJsonPath('data.nextRunAt', '2026-09-06T17:25:00+00:00');
        $this->job();
        $this->heartbeat();
        $this->getJson('/api/collector/status')->assertJsonPath('data.intervalMinutes', 75)->assertJsonPath('data.schedulerHealthy', true);
        $this->travel(6)->minutes();
        $this->getJson('/api/collector/status')->assertJsonPath('data.schedulerHealthy', false);
        foreach ([0, 4, 1441, 5.5] as $minutes) {
            $this->putJson('/api/collector/settings', ['intervalMinutes' => $minutes])->assertUnprocessable();
        }
    }

    public function test_management_lists_preview_but_excludes_other_accounts_and_secrets(): void
    {
        $id = $this->job(['mode' => 'reprocess', 'params' => '{"dry_run":true}', 'context' => '{"secret":"must-not-leak","publish_api":false}']);
        $other = $this->job(['account' => 'other']);
        DB::table('chunks')->insert(['job_id' => $id, 'status' => 'succeeded', 'scope' => '{"day":"2026-09-01"}', 'counts' => '{"unchanged":2}', 'raw' => '{"customer":"must-not-leak"}']);
        $this->getJson('/api/collector/jobs')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.publication', 'preview')->assertDontSee('must-not-leak');
        $this->getJson('/api/collector/jobs/' . $id)->assertOk()->assertJsonPath('data.chunks.items.0.counts.unchanged', 2)->assertDontSee('must-not-leak');
        $this->getJson('/api/collector/jobs/' . $other)->assertNotFound();
    }

    public function test_range_submission_and_archive_preview_force_safe_mode(): void
    {
        $source = $this->job();
        $id = $this->job(['mode' => 'history', 'status' => 'queued']);
        $preview = $this->job(['mode' => 'reprocess', 'params' => '{"dry_run":true}', 'context' => '{"publish_api":false}']);
        Http::fake(['collector.test/*' => Http::sequence()->push(['jobId' => $id], 202)->push(['jobId' => $preview], 202)]);
        $this->postJson('/api/collector/jobs', ['mode' => 'history', 'start' => '2026-09-01', 'end' => '2026-09-07', 'requestId' => (string) Str::uuid()])->assertStatus(202);
        $this->postJson('/api/collector/reprocess', ['mode' => 'reprocess', 'start' => '2026-09-01', 'end' => '2026-09-07', 'requestId' => (string) Str::uuid(), 'sourceJobId' => $source, 'dryRun' => false])->assertStatus(202);
        Http::assertSent(fn ($request) => $request['mode'] === 'reprocess' && $request['dryRun'] === true && $request['sourceJobId'] === $source);
        $this->postJson('/api/collector/jobs', ['mode' => 'reprocess', 'start' => '2026-09-01', 'end' => '2026-09-07', 'requestId' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/api/collector/jobs', ['mode' => 'history', 'start' => '2026-09-08', 'end' => '2026-09-09', 'requestId' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_management_actions_are_independently_authorized(): void
    {
        $user = User::create(['username' => 'manager-viewer', 'display_name' => 'Viewer', 'password_hash' => Hash::make('Test-123'), 'active' => 1, 'role_id' => 2]);
        DB::table('role_permissions')->where('role_id', 2)->delete();
        DB::table('role_permissions')->insert(['role_id' => 2, 'permission_id' => Permission::where('code', 'dashboard.collector')->firstOrFail()->id]);
        $this->withHeader('Authorization', 'Bearer ' . ApiToken::issue($user->id)['plain']);
        $this->getJson('/api/collector/jobs')->assertOk();
        $this->putJson('/api/collector/settings', ['intervalMinutes' => 60])->assertForbidden();
        $this->postJson('/api/collector/jobs', [])->assertForbidden();
        $this->postJson('/api/collector/reprocess', [])->assertForbidden();
        Http::assertNothingSent();
    }
}
