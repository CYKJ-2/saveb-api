<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$tables = ['procurement_tasks','procurement_removed_orders','warehouse_records','purchase_tasks','influencers','influencer_domains','paypal_accounts','paypal_balance_entries','paypal_reviews','paypal_withdrawals','attachments','ocr_jobs','invoice_operation_logs'];
foreach ($tables as $table) {
    $columns = DB::select('select column_name,data_type,is_nullable,column_default from information_schema.columns where table_schema=current_schema() and table_name=? order by ordinal_position', [$table]);
    echo json_encode(['table' => $table,'count' => $columns ? DB::table($table)->count() : null,'columns' => $columns], JSON_UNESCAPED_UNICODE) . "\n";
}
