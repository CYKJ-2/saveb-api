<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$today = now('Asia/Shanghai')->toDateString();
foreach ([['startDate' => $today,'endDate' => $today],['startDate' => '2026-08-01','endDate' => '2026-08-27']] as $range) {
    foreach (App\Services\DashboardOverviewService::MODULES as $module) {
        $start = microtime(true);
        $result = app(App\Services\DashboardOverviewService::class)->show($module, $range);
        echo json_encode(['range' => $range,'module' => $module,'seconds' => round(microtime(true) - $start, 2),'rows' => isset($result['data']['list']) ? count($result['data']['list']) : null,'metrics' => $module === 'overview' ? $result['data']['current'] : null], JSON_UNESCAPED_UNICODE) . "\n";
    }
}
