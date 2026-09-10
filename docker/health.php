<?php
// Readiness checks real application dependencies without exposing connection details.
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
try {
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Illuminate\Support\Facades\DB::select('SELECT 1');
    Illuminate\Support\Facades\DB::table('migrations')->limit(1)->get();
    Illuminate\Support\Facades\Redis::connection()->ping();
    $socket = @fsockopen('127.0.0.1', 9000, $errno, $message, 2);
    if (!$socket) {
        exit(1);
    }
    fclose($socket);
    echo "ready\n";
} catch (Throwable $error) {
    fwrite(STDERR, "application dependency unavailable\n");
    exit(1);
}
