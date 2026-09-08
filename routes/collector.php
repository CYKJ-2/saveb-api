<?php

use App\Controllers\CollectorController;
use App\Controllers\CollectorManagementController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.api')->prefix('collector')->group(function () {
    Route::get('/status', [CollectorController::class, 'status'])->middleware('permission:dashboard.overview.collector_status');
    Route::post('/today', [CollectorController::class, 'today'])->middleware('permission:dashboard.overview.collector_trigger');
    Route::get('/settings', [CollectorManagementController::class, 'settings'])->middleware('permission:dashboard.collector');
    Route::put('/settings', [CollectorManagementController::class, 'saveSettings'])->middleware('permission:dashboard.collector.settings');
    Route::get('/jobs', [CollectorManagementController::class, 'jobs'])->middleware('permission:dashboard.collector');
    Route::get('/jobs/{id}', [CollectorManagementController::class, 'detail'])->middleware('permission:dashboard.collector');
    Route::post('/jobs', [CollectorManagementController::class, 'collect'])->middleware('permission:dashboard.collector.collect');
    Route::post('/reprocess', [CollectorManagementController::class, 'reprocess'])->middleware('permission:dashboard.collector.reprocess');
});
