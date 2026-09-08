<?php

/**
 * End-to-end auth integration test.
 * Run: docker exec saveb-api-app php scripts/test_auth.php
 *
 * Coverage:
 *   1. Wrong password          → 401 SystemException
 *   2. Unknown username        → 401 SystemException
 *   3. Empty body             → 422 ValidationException
 *   4. Login configured test account → 200 + token
 *   5. /api/auth/me with token → 200 + user info
 *   6. /api/users without token → 401
 *   7. /api/users with token   → 200
 *   8. Logout                 → 200
 *   9. Token after logout     → 401
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
use App\Exceptions\SystemException;
use App\Middleware\Authenticate;
use App\Middleware\RequireRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

function section(string $title): void
{
    echo "\n\033[1;36m" . str_repeat('=', 60) . "\033[0m\n  $title\n" . str_repeat('=', 60) . "\033[0m\n";
}

function assertEq(int $expected, int $actual, string $label): void
{
    $ok = $expected === $actual;
    $color = $ok ? "\033[1;32m" : "\033[1;31m";
    echo "  {$color}" . ($ok ? '✓' : '✗') . "\033[0m $label — expected $expected, got $actual\n";
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

function decode(JsonResponse $r): array
{
    $out = json_decode($r->getContent(), true);

    return is_array($out) ? $out : [];
}

$authMw  = new Authenticate();
$roleMw  = new RequireRoles();
$authCtrl   = app(AuthController::class);
$usersCtrl  = app(UserController::class);

try {
    // 1. Wrong password → SystemException(401)
    section('1. POST /api/auth/login — wrong password');
    try {
        $req = Request::create(
            '/api/auth/login',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['username' => $testUsername, 'password' => 'wrong-password']),
        );
        $req->headers->set('Content-Type', 'application/json');
        $authCtrl->login($req);
        fwrite(STDERR, "  ✗ expected SystemException\n");
        exit(1);
    } catch (SystemException $e) {
        assertEq(401, $e->getHttpStatus(), 'status code');
        echo "  code: {$e->getApiCode()}, message: " . substr($e->getMessage(), 0, 60) . "\n";
    }

    // 2. Unknown username → SystemException(401)
    section('2. POST /api/auth/login — unknown user');
    try {
        $req = Request::create(
            '/api/auth/login',
            'POST',
            [],
            [],
            [],
            [],
            json_encode(['username' => 'no-such-user', 'password' => 'whatever']),
        );
        $req->headers->set('Content-Type', 'application/json');
        $authCtrl->login($req);
        fwrite(STDERR, "  ✗ expected SystemException\n");
        exit(1);
    } catch (SystemException $e) {
        assertEq(401, $e->getHttpStatus(), 'status code');
        echo "  code: {$e->getApiCode()}\n";
    }

    // 3. Empty body → ValidationException
    section('3. POST /api/auth/login — empty body');
    $validationCaught = false;
    try {
        $req = Request::create('/api/auth/login', 'POST', [], [], [], [], json_encode([]));
        $req->headers->set('Content-Type', 'application/json');
        $authCtrl->login($req);
    } catch (\Illuminate\Validation\ValidationException $e) {
        $validationCaught = true;
    }
    assertTrue($validationCaught, 'ValidationException thrown');
    echo "  caught ValidationException (Laravel returns 422)\n";

    // 4. Happy path login
    section('4. POST /api/auth/login — configured test account');
    $req = Request::create('/api/auth/login', 'POST', [], [], [], [], json_encode([
        'username'   => $testUsername,
        'password'   => $testPassword,
        'token_name' => 'cli-test',
    ]));
    $req->headers->set('Content-Type', 'application/json');
    $res = $authCtrl->login($req);
    assertEq(200, $res->getStatusCode(), 'status code');
    $d = decode($res);
    $token = $d['data']['token'] ?? null;
    $user  = $d['data']['user']  ?? [];
    echo "  user       : #{$user['id']} {$user['username']} ({$user['display_name']})\n";
    echo "  role       : {$user['role']}\n";
    echo "  mcp        : " . ($user['must_change_password'] ? 'yes' : 'no') . "\n";
    echo "  token      : " . substr((string) $token, 0, 20) . "...\n";
    echo "  expires_at : {$d['data']['expires_at']}\n";

    // 5. /api/auth/me with token
    section('5. GET /api/auth/me (with Bearer)');
    $req = Request::create('/api/auth/me', 'GET');
    $req->headers->set('Authorization', 'Bearer ' . $token);
    $res = $authMw->handle($req, fn ($r) => $authCtrl->me($r));
    assertEq(200, $res->getStatusCode(), 'status code');
    $d = decode($res);
    echo "  me : #{$d['data']['user']['id']} {$d['data']['user']['username']} ({$d['data']['user']['role']})\n";

    // 6. /api/users WITHOUT token
    section('6. GET /api/users (NO token) — should be 401');
    $req = Request::create('/api/users', 'GET');
    $res = $authMw->handle($req, fn ($r) => $usersCtrl->index($r));
    assertEq(401, $res->getStatusCode(), 'status code');
    $d = decode($res);
    echo "  code: {$d['code']}\n";

    // 7. /api/users WITH token
    section('7. GET /api/users (with Bearer)');
    $req = Request::create('/api/users', 'GET');
    $req->headers->set('Authorization', 'Bearer ' . $token);
    $res = $authMw->handle($req, fn ($r) => $roleMw->handle(
        $r,
        fn ($r2) => $usersCtrl->index($r2),
        'admin',
        'viewer',
        'customer_service',
        'customer_service_client',
    ));
    assertEq(200, $res->getStatusCode(), 'status code');
    $d = decode($res);
    echo "  total users: " . count($d['data']) . " (meta total: {$d['meta']['total']})\n";

    // 8. Logout
    section('8. POST /api/auth/logout');
    $req = Request::create('/api/auth/logout', 'POST');
    $req->headers->set('Authorization', 'Bearer ' . $token);
    $res = $authMw->handle($req, fn ($r) => $authCtrl->logout($r));
    $d = decode($res);
    echo "  success: " . ($d['success'] ? 'true' : 'false') . "\n";

    // 9. Token after logout
    section('9. GET /api/users (token revoked) — should be 401');
    $req = Request::create('/api/users', 'GET');
    $req->headers->set('Authorization', 'Bearer ' . $token);
    $res = $authMw->handle($req, fn ($r) => $usersCtrl->index($r));
    assertEq(401, $res->getStatusCode(), 'status code');
    $d = decode($res);
    echo "  code: {$d['code']}\n";

    section('All auth tests passed!');
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "\n\033[1;31mERROR:\033[0m {$e->getMessage()}\n{$e->getTraceAsString()}\n");
    exit(1);
}
