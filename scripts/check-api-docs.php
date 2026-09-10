<?php

declare(strict_types=1);

// 默认只检查路由覆盖及文档一致性；--local-read-only 可额外核查本地只读 JSON 接口。
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/api-docs/SourceInspector.php';
require __DIR__ . '/api-docs/Schemas.php';
require __DIR__ . '/api-docs/Contracts.php';
require __DIR__ . '/api-docs/Builder.php';

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$document = json_decode(file_get_contents(dirname(__DIR__) . '/public/api-docs/openapi.json'), true, flags: JSON_THROW_ON_ERROR);
$expected = json_decode(json_encode((new ApiDocs\Builder())->build(Illuminate\Support\Facades\Route::getRoutes()), JSON_THROW_ON_ERROR), true);
// version 记录生成日期，跨日检查不应误判为接口契约发生变化。
$expected['info']['version'] = $document['info']['version'];
$errors = [];
if ($document !== $expected) {
    $errors[] = '文档与当前源码不同，请先运行 php scripts/generate-api-docs.php。';
}
$ids = [];
foreach ($document['paths'] as $path => $operations) {
    foreach ($operations as $operation) {
        $id = $operation['operationId'];
        if (isset($ids[$id])) {
            $errors[] = 'operationId 重复：' . $id;
        }
        $ids[$id] = true;
    }
}
$walkRefs = function ($node) use (&$walkRefs, &$errors, $document): void {
    if (!is_array($node)) {
        return;
    }
    if (isset($node['$ref'])) {
        $name = basename($node['$ref']);
        if (!isset($document['components']['schemas'][$name])) {
            $errors[] = '不存在的 schema：' . $name;
        }
    }
    foreach ($node as $value) {
        $walkRefs($value);
    }
};
$walkRefs($document);
echo '静态文档：' . count($ids) . ' 个接口，' . count($document['components']['schemas']) . " 个结构。\n";

/** 核对返回类型及必有字段；错误仅输出路径和类型，绝不输出真实响应值。 */
function checkResponse(mixed $data, array $schema, array $models, string $path = '$'): array
{
    if (isset($schema['$ref'])) {
        return checkResponse($data, $models[basename($schema['$ref'])], $models, $path);
    }
    if (isset($schema['anyOf'])) {
        foreach ($schema['anyOf'] as $branch) {
            if (!checkResponse($data, $branch, $models, $path)) {
                return [];
            }
        }

        return ["$path 不满足声明的任一类型（实际 " . get_debug_type($data) . '）'];
    }
    if (isset($schema['allOf'])) {
        return array_merge([], ...array_map(fn ($branch) => checkResponse($data, $branch, $models, $path), $schema['allOf']));
    }
    $kinds = (array) ($schema['type'] ?? []);
    $fits = $kinds === [];
    foreach ($kinds as $kind) {
        $fits = $fits || match ($kind) {
            'object' => is_object($data), 'array' => is_array($data), 'integer' => is_int($data),
            'number' => is_int($data) || is_float($data), 'boolean' => is_bool($data),
            'null' => $data === null, 'string' => is_string($data), default => false,
        };
    }
    if (!$fits) {
        return ["$path 期望 " . implode('|', $kinds) . '，实际 ' . get_debug_type($data)];
    }
    $errors = [];
    if (is_object($data)) {
        foreach ($schema['required'] ?? [] as $field) {
            if (!property_exists($data, $field)) {
                $errors[] = "$path.$field 缺失";
            }
        }
        foreach ($schema['properties'] ?? [] as $field => $child) {
            if (property_exists($data, $field)) {
                $errors = array_merge($errors, checkResponse($data->$field, $child, $models, "$path.$field"));
            }
        }
        if (isset($schema['properties']) && ($schema['additionalProperties'] ?? false) !== true) {
            foreach (array_diff(array_keys((array) $data), array_keys($schema['properties'])) as $field) {
                $errors[] = "$path.$field 实际返回但尚未说明";
            }
        }
    }
    if (is_array($data) && isset($schema['items'])) {
        foreach ($data as $index => $item) {
            $errors = array_merge($errors, checkResponse($item, $schema['items'], $models, "$path.$index"));
        }
    }

    return array_values(array_unique($errors));
}

if (in_array('--local-read-only', $argv, true)) {
    if (!$app->environment('local')) {
        fwrite(STDERR, "本地只读响应核查仅允许 APP_ENV=local。\n");
        exit(1);
    }
    $db = Illuminate\Support\Facades\DB::connection('pgsql');
    $db->beginTransaction();
    $db->statement('SET TRANSACTION READ ONLY');
    $db->statement("SET LOCAL statement_timeout='30s'");
    // 只在当前 CLI 进程绕过认证，避免生成真实 token 或修改 token.last_used_at。
    // 不启动 HTTP Kernel，不安装路由，也不修改线上/本地认证逻辑。
    $app->instance('middleware.disable', true);
    $actor = App\Models\User::where('active', true)->firstOrFail();
    $recordIds = [
        'users' => $actor->id,
        'roles' => App\Models\Role::query()->value('id'),
        'permissions' => App\Models\Permission::query()->value('id'),
        'orders' => App\Models\Order::query()->value('id'),
        'invoices' => App\Models\InvoiceOrder::query()->value('id'),
        'jobs' => App\Models\CollectorJob::query()->value('id'),
    ];
    $email = App\Models\PaypalAccount::query()->value('email');
    $checked = 0;
    $skipped = 0;
    try {
        foreach ($document['paths'] as $path => $operations) {
            $operation = $operations['get'] ?? null;
            if (!$operation || str_contains($path, '/export') || str_contains($path, '/attachments/') || str_contains($path, '/logistics/')) {
                continue;
            }
            $actualPath = $path;
            if (str_contains($path, '{id}')) {
                preg_match('#/([^/]+)/\{id\}#', $path, $match);
                $id = $recordIds[$match[1]] ?? null;
                if (!$id) {
                    $skipped++;
                    continue;
                }
                $actualPath = str_replace('{id}', (string) $id, $path);
            }
            $params = ['page' => 1, 'per_page' => 1];
            foreach ($operation['parameters'] as $parameter) {
                if ($parameter['in'] !== 'query') {
                    continue;
                }
                $name = $parameter['name'];
                if (in_array($name, ['startDate', 'endDate'], true)) {
                    $params[$name] = '2026-09-07';
                } elseif ($name === 'month') {
                    $params[$name] = '2026-09';
                } elseif ($name === 'email') {
                    $params[$name] = $email;
                } elseif ($parameter['required']) {
                    $params[$name] = $parameter['schema']['enum'][0] ?? 'example';
                }
            }
            $request = Illuminate\Http\Request::create($actualPath, 'GET', $params, server: ['HTTP_ACCEPT' => 'application/json']);
            $request->attributes->set('auth_user', $actor);
            $request->attributes->set('auth_permission_codes', ['*']);
            $request->attributes->set('auth_token', (new App\Models\ApiToken())->forceFill(['id' => 0, 'name' => 'documentation-check']));
            $app->instance('request', $request);
            try {
                $response = $app->make('router')->dispatch($request);
                $status = (string) $response->getStatusCode();
                $schema = $operation['responses'][$status]['content']['application/json']['schema'] ?? null;
                if (!$schema || !str_starts_with($status, '2')) {
                    $errors[] = "GET $path 返回非预期状态 $status";
                } else {
                    $data = json_decode($response->getContent(), flags: JSON_THROW_ON_ERROR);
                    foreach (checkResponse($data, $schema, $document['components']['schemas']) as $error) {
                        $errors[] = "GET $path $error";
                    }
                }
                $checked++;
            } catch (Throwable $exception) {
                $errors[] = "GET $path 抛出 " . get_class($exception);
            }
        }
    } finally {
        $db->rollBack();
    }
    echo "本地 GET 返回结构：核查 $checked 个接口，缺少样本跳过 $skipped 个；数据库只读事务已回滚。\n";
}

foreach (array_slice($errors, 0, 100) as $error) {
    fwrite(STDERR, $error . "\n");
}
echo '错误数：' . count($errors) . "\n";
exit($errors ? 1 : 0);
