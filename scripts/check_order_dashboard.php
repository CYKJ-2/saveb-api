<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$service = app(App\Services\OrderStatisticsService::class);
foreach (['overview','currencies','sales-trend','categories','influencers','staff'] as $module) {
    $start = microtime(true);
    $data = $service->statistics($module, ['startDate' => '2026-08-01','endDate' => '2026-08-31']);
    echo json_encode(['module' => $module,'seconds' => round(microtime(true) - $start, 2),'result' => $module === 'overview' ? $data : count($data['list'])]) . "\n";
}
