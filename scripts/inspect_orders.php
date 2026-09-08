<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

foreach (['orders','invoice_orders','exchange_rates','order_user_overrides','order_staff_allocations'] as $table) {
    echo $table . ': ' . DB::table($table)->count() . "\n";
    echo json_encode(DB::select('select column_name,data_type from information_schema.columns where table_schema=current_schema() and table_name=?', [$table])) . "\n";
}
echo json_encode(DB::table('orders')->select('classification', 'order_status')->selectRaw('count(*) as n')->groupBy('classification', 'order_status')->get()) . "\n";
echo json_encode(DB::table('orders')->selectRaw('min(order_time) as first,max(order_time) as last')->first()) . "\n";
echo json_encode(DB::table('exchange_rates')->limit(5)->get()) . "\n";
echo json_encode(DB::table('invoice_orders')->select('invoice_status')->selectRaw('count(*) as n')->groupBy('invoice_status')->get()) . "\n";
