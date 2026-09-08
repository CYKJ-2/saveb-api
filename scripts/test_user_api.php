<?php
/**
 * Quick integration test for the User API endpoints.
 * Run inside the app container:
 *   docker exec saveb-api-app php scripts/test_user_api.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$app    = require $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App.Controllers\UserController;

$controller = app(UserController::class);

function section(string $title): void
{
    echo "\n\033[1;36m" . str_repeat('=', 60) . "\033[0m\n  $title\n" . str_repeat('=', 60) . "\033[0m\n";
}

function rjson(JsonResponse $r): array
{
    return json_decode($r->getContent(), true);
}

try {
    // --- GET /api/users ---
    section('GET /api/users?per_page=2&page=1');
    $req = Request::create('/api/users', 'GET', ['per_page' => 2, 'page' => 1]);
    $res = $controller->index($req);
    $data = rjson($res);
    echo "  status : {$res->getStatusCode()}\n";
    echo "  success: " . ($data['success'] ?? false ? 'true' : 'false') . "\n";
    echo "  total  : " . ($data['meta']['total'] ?? '?') . "\n";
    echo "  page   : {$data['meta']['current_page']} / {$data['meta']['last_page']}\n";
    foreach (($data['data'] ?? []) as $u) {
        printf("    #%-4d %-20s %s\n", $u['id'], $u['username'], $u['display_name']);
    }

    // --- GET /api/users/all ---
    section('GET /api/users/all');
    $req = Request::create('/api/users/all', 'GET', ['active' => 'true']);
    $res = $controller->all($req);
    $data = rjson($res);
    echo "  status : {$res->getStatusCode()}\n";
    echo "  success: " . ($data['success'] ?? false ? 'true' : 'false') . "\n";
    echo "  total  : " . ($data['meta']['total'] ?? '?') . "\n";

    // --- GET /api/users/count ---
    section('GET /api/users/count');
    $req = Request::create('/api/users/count', 'GET');
    $res = $controller->count($req);
    $data = rjson($res);
    echo "  status : {$res->getStatusCode()}\n";
    echo "  total  : " . ($data['data']['total'] ?? '?') . "\n";

    // --- GET /api/users/1 (show) ---
    section('GET /api/users/7 (show first existing user)');
    $res = $controller->show(7);
    $data = rjson($res);
    echo "  status : {$res->getStatusCode()}\n";
    echo "  success: " . ($data['success'] ?? false ? 'true' : 'false') . "\n";
    if ($data['success']) {
        echo "  user   : #{$data['data']['id']} {$data['data']['username']} ({$data['data']['role']})\n";
    }

    // --- GET /api/users/99999 (not found) ---
    section('GET /api/users/99999 (not found)');
    $res = $controller->show(99999);
    echo "  status : {$res->getStatusCode()}\n";
    $data = rjson($res);
    echo "  success: " . ($data['success'] ?? false ? 'true' : 'false') . "\n";
    echo "  message: " . ($data['message'] ?? '—') . "\n";

    section('All tests passed!');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\n\033[1;31mERROR:\033[0m {$e->getMessage()}\n{$e->getTraceAsString()}\n");
    exit(1);
}
