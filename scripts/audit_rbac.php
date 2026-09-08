<?php

// Read-only consistency report. Never prints passwords, hashes or bearer tokens.
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$permissions = App\Models\Permission::all()->keyBy('id');
$codes = $permissions->pluck('code')->all();
$missing = [];
foreach (Illuminate\Support\Facades\Route::getRoutes() as $route) {
    foreach ($route->gatherMiddleware() as $middleware) {
        if (! str_starts_with($middleware, 'permission:')) {
            continue;
        }
        foreach (preg_split('/[,|]/', substr($middleware, 11)) as $code) {
            if (! in_array($code, $codes, true)) {
                $missing[$code] = $code;
            }
        }
    }
}
$invalidParents = [];
foreach ($permissions as $node) {
    $seen = [];
    $current = $node;
    while ($current && $current->parent_id) {
        if (isset($seen[$current->id]) || ! $permissions->has($current->parent_id)) {
            $invalidParents[] = $node->id;
            break;
        }
        $seen[$current->id] = true;
        $current = $permissions->get($current->parent_id);
    }
}
echo json_encode([
    'users' => App\Models\User::all()->map(fn ($u) => [
        'id' => $u->id, 'active' => $u->active,
        'roles' => $u->effectiveRoleCodes(),
        'permission_codes' => app(App\Services\RbacService::class)->codes($u),
    ]),
    'missing_route_permissions' => array_values($missing),
    'invalid_parent_node_ids' => $invalidParents,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
