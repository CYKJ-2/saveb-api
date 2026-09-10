<?php

declare(strict_types=1);

// 生成静态文档：只启动路由元数据及读取源码，不发起 HTTP 请求、不查询业务数据库。
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/api-docs/SourceInspector.php';
require __DIR__ . '/api-docs/Schemas.php';
require __DIR__ . '/api-docs/Contracts.php';
require __DIR__ . '/api-docs/Builder.php';

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $builder = new ApiDocs\Builder();
    $spec = $builder->build(Illuminate\Support\Facades\Route::getRoutes());
    $output = dirname(__DIR__) . '/public/api-docs';
    if (!is_dir($output)) {
        mkdir($output, 0755, true);
    }
    $json = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $embedded = json_encode($spec, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $template = file_get_contents(__DIR__ . '/api-docs/viewer.html');
    if (!str_contains($template, '__OPENAPI_DOCUMENT__')) {
        throw new RuntimeException('预览模板缺少文档数据占位符');
    }
    file_put_contents($output . '/openapi.json', $json . "\n");
    file_put_contents($output . '/index.html', str_replace('__OPENAPI_DOCUMENT__', $embedded, $template));
    echo '已生成 ' . $spec['x-route-count'] . ' 个接口、' . count($spec['components']['schemas']) . " 个数据结构。\n";
    echo "预览：public/api-docs/index.html\nOpenAPI：public/api-docs/openapi.json\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n" . $error->getTraceAsString() . "\n");
    exit(1);
}
