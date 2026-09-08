<?php

namespace App\Providers;

use App\Util\SqlLogger;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Capture every SQL query into storage/logs/sql-YYYY-MM-DD.log.
        // Slow / failed queries also go to sql_error.log.
        SqlLogger::register();
    }
}
