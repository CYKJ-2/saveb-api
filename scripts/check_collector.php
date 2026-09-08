<?php

// 只读检查当前 API 数据库的采集状态和到采集器的网络连通性。
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$status = $app->make(App\Services\CollectorService::class)->status();
$response = Illuminate\Support\Facades\Http::timeout(5)->get(rtrim(config('collector.url'), '/').'/health');
echo json_encode(['collectorHttpStatus' => $response->status(), 'status' => $status], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
