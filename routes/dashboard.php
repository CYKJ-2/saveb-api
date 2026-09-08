<?php

use App\Controllers\DashboardOverviewController;
use App\Services\DashboardOverviewService;
use Illuminate\Support\Facades\Route;

Route::prefix('dashboard')->middleware('auth.api')->group(function () {
    foreach (DashboardOverviewService::MODULES as $module) {
        Route::get($module, [DashboardOverviewController::class,'show'])->defaults('module', $module)
            ->middleware('permission:dashboard.overview.' . str_replace('-', '_', $module));
    }
});
