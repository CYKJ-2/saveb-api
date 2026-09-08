<?php

/**
 * Demo: verify saveb-api can read from the synced ERP database.
 *
 * Run from the saveb-api project root:
 *   php scripts/db_demo.php
 *
 * What it does:
 *   - Bootstraps the Laravel application (so .env + DB config are honoured).
 *   - Lists tables, then samples a handful of common ERP tables.
 *   - Prints row counts and a few recent rows.
 */

declare(strict_types=1);

// Find the project root (this file lives in scripts/, project root is one level up)
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$app    = require $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// --- Pretty helpers ---
function section(string $title): void
{
    echo "\n\033[1;36m" . str_repeat('=', 60) . "\033[0m\n";
    echo "\033[1;36m  $title\033[0m\n";
    echo "\033[1;36m" . str_repeat('=', 60) . "\033[0m\n";
}

function printRow(array $cols, array $values): void
{
    foreach ($cols as $i => $col) {
        $val = $values[$i] ?? null;
        if (is_null($val)) {
            $val = '<null>';
        }
        echo sprintf("  %-22s : %s\n", $col, mb_strimwidth((string) $val, 0, 120, '…'));
    }
    echo "  " . str_repeat('-', 56) . "\n";
}

try {
    // --- 1) Connection probe ---
    section('1. Connection');
    $pdo    = DB::connection()->getPdo();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $server = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    echo "  Driver   : $driver\n";
    echo "  Server   : PostgreSQL $server\n";
    echo "  Database : " . DB::connection()->getDatabaseName() . "\n";
    echo "  Host     : " . config('database.connections.pgsql.host') . "\n";
    echo "  Port     : " . config('database.connections.pgsql.port') . "\n";

    // --- 2) List tables ---
    section('2. Tables (first 20)');
    $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename LIMIT 20");
    foreach ($tables as $t) {
        echo "  - {$t->tablename}\n";
    }
    echo "  total: " . count(DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public'")) . "\n";

    // --- 3) Sample a few common tables (some may not exist in your data) ---
    section('3. Row counts (safe — skips missing tables)');
    $candidates = ['users', 'orders', 'customers', 'products', 'invoices', 'customers_address'];
    foreach ($candidates as $tbl) {
        if (! schemaHasTable($tbl)) {
            continue;
        }
        $count = DB::table($tbl)->count();
        printf("  %-22s : %d rows\n", $tbl, $count);
    }

    // --- 4) Show 3 recent users ---
    if (schemaHasTable('users')) {
        section('4. Recent users (3)');
        $cols = schemaColumns('users');
        $rows = DB::table('users')->orderByDesc('id')->limit(3)->get($cols);
        foreach ($rows as $r) {
            printRow($cols, array_map(fn ($c) => $r->$c, $cols));
        }
    }

    // --- 5) Show 3 recent orders (if id+created_at columns exist) ---
    if (schemaHasTable('orders')) {
        section('5. Recent orders (3)');
        $cols = array_intersect(['id', 'order_no', 'status', 'total', 'created_at'], schemaColumns('orders'));
        if (! $cols) {
            $cols = schemaColumns('orders');
        }
        $rows = DB::table('orders')->orderByDesc('id')->limit(3)->get(array_values($cols));
        foreach ($rows as $r) {
            printRow(array_values($cols), array_map(fn ($c) => $r->$c, array_values($cols)));
        }
    }

    section('Done');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\n\033[1;31mERROR:\033[0m {$e->getMessage()}\n");
    exit(1);
}

// --- helpers (declared after use so they're available in this file) ---
function schemaHasTable(string $table): bool
{
    return \Illuminate\Support\Facades\Schema::hasTable($table);
}

function schemaColumns(string $table): array
{
    return \Illuminate\Support\Facades\Schema::getColumnListing($table);
}
