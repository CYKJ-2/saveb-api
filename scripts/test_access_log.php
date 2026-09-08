<?php

/**
 * End-to-end test for TraceId + AccessLog middleware.
 * Run: docker exec saveb-api-app php scripts/test_access_log.php
 *
 * Coverage:
 *   1. No trace_id header → server generates req_xxx, echoes in Trace-Id header
 *   2. Frontend supplies X-Trace-Id → echoed back unchanged
 *   3. X-Correlation-Id (alt header) → used as trace_id
 *   4. Invalid chars in trace_id → fallback to generated
 *   5. POST /api/auth/login (real) → 200, logged
 *   6. GET /api/auth/me (bad token) → 401, logged as warning
 *   7. /api/users (no token) → 401, logged
 *   8. /api/users (valid token) → 200, logged
 *   9. access.log file: written, trace_id present in all entries
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// 手动诊断需要显式提供测试账号，源码中不保存真实登录凭据。
$testUsername = getenv('SAVEB_TEST_USERNAME');
$testPassword = getenv('SAVEB_TEST_PASSWORD');
if (!$testUsername || !$testPassword) {
    fwrite(STDERR, "Set SAVEB_TEST_USERNAME and SAVEB_TEST_PASSWORD before running this diagnostic.\n");
    exit(1);
}

use App\Controllers\AuthController;
use App\Controllers\UserController;
use App\Middleware\AccessLog;
use App\Middleware\Authenticate;
use App\Middleware\RequireRoles;
use App\Middleware\TraceId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

function section(string $title): void
{
    echo "\n\033[1;36m" . str_repeat('=', 60) . "\033[0m\n  $title\n" . str_repeat('=', 60) . "\033[0m\n";
}

function assertEq($expected, $actual, string $label): void
{
    $ok = $expected === $actual;
    $color = $ok ? "\033[1;32m" : "\033[1;31m";
    $exp = is_string($expected) ? "\"$expected\"" : (string) $expected;
    $act = is_string($actual) ? "\"$actual\"" : (string) $actual;
    echo "  {$color}" . ($ok ? '✓' : '✗') . "\033[0m $label — expected $exp, got $act\n";
    if (!$ok) {
        exit(1);
    }
}

function assertTrue(bool $cond, string $label): void
{
    $color = $cond ? "\033[1;32m" : "\033[1;31m";
    echo "  {$color}" . ($cond ? '✓' : '✗') . "\033[0m $label\n";
    if (!$cond) {
        exit(1);
    }
}

// Reset access log
$logFile = storage_path('logs/access.log');
if (file_exists($logFile)) {
    unlink($logFile);
}

// Middleware instances
$traceId   = new TraceId();
$accessLog  = new AccessLog();
$authMw     = new Authenticate();
$roleMw     = new RequireRoles();
$authCtrl   = app(AuthController::class);
$usersCtrl  = app(UserController::class);

/**
 * Run request through AccessLog → TraceId → [additional middleware] → terminal.
 * Order: AccessLog (outermost) → TraceId → authMw → roleMwWithParams → terminal
 */
function run(Request $req, array $mws, callable $terminal): JsonResponse
{
    // Build the full chain in execution order
    $chain = array_merge([new AccessLog(), new TraceId()], $mws);

    $handler = $terminal;
    // Wrap from LAST to FIRST (so the first item in $chain becomes outermost)
    foreach (array_reverse($chain) as $mw) {
        $nextHandler = $handler;
        if ($mw instanceof \Closure) {
            $handler = fn (Request $r) => $mw($r, $nextHandler);
        } else {
            $handler = fn (Request $r) => $mw->handle($r, $nextHandler);
        }
    }

    return $handler($req);
}

try {
    // 1. No X-Trace-Id header → server generates req_xxx
    section('1. GET /api/users (no trace header) — server must generate req_xxx');
    $req = Request::create('/api/users', 'GET');
    $res = run($req, [], fn ($r) => response()->json(['noop' => true]));
    assertEq(200, $res->getStatusCode(), 'status code');
    $hdr = $res->headers->get('X-Trace-Id');
    echo "  X-Trace-Id (echoed): $hdr\n";
    assertTrue($hdr !== null && str_starts_with($hdr, 'req_'), 'echoed id starts with req_');
    $generated = $hdr;

    // 2. Frontend supplies X-Trace-Id
    section('2. GET /api/users (with X-Trace-Id) — client value wins');
    $clientId = 'web-' . str_repeat('a', 30);
    $req = Request::create('/api/users', 'GET');
    $req->headers->set('X-Trace-Id', $clientId);
    $res = run($req, [], fn ($r) => response()->json(['noop' => true]));
    assertEq(200, $res->getStatusCode(), 'status code');
    assertEq($clientId, $res->headers->get('X-Trace-Id'), 'echoed id matches client');
    echo "  echoed: {$res->headers->get('X-Trace-Id')}\n";

    // 3. X-Correlation-Id (alt header)
    section('3. GET /api/users (X-Correlation-Id) — alt header accepted');
    $cid = 'corr-12345';
    $req = Request::create('/api/users', 'GET');
    $req->headers->set('X-Correlation-Id', $cid);
    $res = run($req, [], fn ($r) => response()->json(['noop' => true]));
    assertEq(200, $res->getStatusCode(), 'status code');
    assertEq($cid, $res->headers->get('X-Trace-Id'), 'correlation id is used as trace id');
    echo "  echoed: {$res->headers->get('X-Trace-Id')}\n";

    // 4. Invalid chars → fallback
    section('4. GET /api/users (X-Trace-Id with invalid chars) — fallback');
    $bad = 'has spaces and 漢字!';
    $req = Request::create('/api/users', 'GET');
    $req->headers->set('X-Trace-Id', $bad);
    $res = run($req, [], fn ($r) => response()->json(['noop' => true]));
    $hdr = $res->headers->get('X-Trace-Id');
    echo "  echoed: $hdr (original was: $bad)\n";
    assertTrue($hdr !== $bad, 'rejected invalid id');
    assertTrue(str_starts_with($hdr, 'req_'), 'fallback id starts with req_');

    // 5. Real login
    section('5. POST /api/auth/login (real)');
    $loginId = 'login-' . bin2hex(random_bytes(6));
    $req = Request::create('/api/auth/login', 'POST', [], [], [], [], json_encode([
        'username' => $testUsername, 'password' => $testPassword,
    ]));
    $req->headers->set('Content-Type', 'application/json');
    $req->headers->set('X-Trace-Id', $loginId);
    $res = run($req, [], fn ($r) => $authCtrl->login($r));
    assertEq(200, $res->getStatusCode(), 'status code');
    assertEq($loginId, $res->headers->get('X-Trace-Id'), 'login id echoed');
    $data = json_decode($res->getContent(), true);
    $token = $data['data']['token'] ?? null;
    assertTrue((bool) $token, 'token issued');
    echo "  user: #{$data['data']['user']['id']} {$data['data']['user']['username']}\n";

    // 6. 401 (bad token)
    section('6. GET /api/auth/me (bad token) — 401 logged');
    $badId = 'bad-' . bin2hex(random_bytes(6));
    $req = Request::create('/api/auth/me', 'GET');
    $req->headers->set('Authorization', 'Bearer saveb_invalid_token');
    $req->headers->set('X-Trace-Id', $badId);
    $res = run($req, [$authMw], fn ($r) => $authCtrl->me($r));
    assertEq(401, $res->getStatusCode(), 'status code');
    assertEq($badId, $res->headers->get('X-Trace-Id'), 'id echoed on 401');
    echo "  status: 401, echoed: {$res->headers->get('X-Trace-Id')}\n";

    // 7. 401 (no token)
    section('7. GET /api/users (no token) — 401 logged');
    $ntId = 'no-token-' . bin2hex(random_bytes(6));
    $req = Request::create('/api/users', 'GET');
    $req->headers->set('X-Trace-Id', $ntId);
    $res = run($req, [$authMw], fn ($r) => $usersCtrl->index($r));
    assertEq(401, $res->getStatusCode(), 'status code');
    assertEq($ntId, $res->headers->get('X-Trace-Id'), 'id echoed');
    echo "  echoed: {$res->headers->get('X-Trace-Id')}\n";

    // 8. 200 (valid token)
    section('8. GET /api/users (valid token) — 200 logged');
    $okId = 'ok-' . bin2hex(random_bytes(6));
    $req = Request::create('/api/users', 'GET');
    $req->headers->set('Authorization', 'Bearer ' . $token);
    $req->headers->set('X-Trace-Id', $okId);
    $roleMwWithParams = function (Request $r, \Closure $next) use ($roleMw) {
        return $roleMw->handle($r, $next, 'admin', 'viewer', 'customer_service', 'customer_service_client');
    };
    $res = run($req, [$authMw, $roleMwWithParams], fn ($r) => $usersCtrl->index($r));
    assertEq(200, $res->getStatusCode(), 'status code');
    assertEq($okId, $res->headers->get('X-Trace-Id'), 'id echoed');
    echo "  echoed: {$res->headers->get('X-Trace-Id')}\n";

    // 9. Verify access.log
    section('9. Verify access.log entries');
    $dir = dirname($logFile);
    $files = glob($dir . '/access-*.log') ?: [];
    if (!$files && file_exists($logFile)) {
        $files = [$logFile];
    }
    assertTrue(!empty($files), 'access log file exists');
    $content = '';
    foreach ($files as $f) {
        $content .= file_get_contents($f);
    }
    echo "  files: " . implode(', ', array_map('basename', $files)) . "\n";
    echo "  total size: " . strlen($content) . " bytes\n";

    foreach ([$loginId, $badId, $ntId, $okId] as $id) {
        $present = str_contains($content, $id);
        $color = $present ? "\033[1;32m" : "\033[1;31m";
        echo "  {$color}" . ($present ? '✓' : '✗') . "\033[0m trace_id \"$id\" found in log\n";
        if (!$present) {
            echo "  --- last 600 bytes ---\n" . substr($content, -600) . "\n";
            exit(1);
        }
    }

    // Check a log line structure
    $lines = array_filter(explode("\n", $content));
    $lastWithId = '';
    foreach (array_reverse($lines) as $line) {
        if (preg_match('/"trace_id":"([^"]+)"/', $line, $m) && $m[1] !== '') {
            $lastWithId = $line;
            break;
        }
    }
    assertTrue($lastWithId !== '', 'found a log line with non-empty trace_id');
    echo "\n  sample log line (most recent with id):\n    " . $lastWithId . "\n";
    assertTrue(str_contains($lastWithId, 'http_access'), 'log line tagged http_access');
    assertTrue(str_contains($lastWithId, '"trace_id"'), 'log line has trace_id field');
    assertTrue(str_contains($lastWithId, '"duration_ms"'), 'log line has duration_ms field');
    assertTrue(str_contains($lastWithId, '"status"'), 'log line has status field');
    assertTrue(str_contains($lastWithId, '"path"'), 'log line has path field');

    section('All access-log tests passed!');
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "\n\033[1;31mERROR:\033[0m {$e->getMessage()}\n{$e->getTraceAsString()}\n");
    exit(1);
}
