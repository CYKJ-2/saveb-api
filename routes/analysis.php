<?php

use App\Controllers\AnalysisController;
use Illuminate\Support\Facades\Route;

Route::prefix('workbench/analysis')->middleware(['auth.api', 'permission:business.analysis.list'])->group(function (): void {
    Route::get('options', [AnalysisController::class, 'options']);
    Route::get('report', [AnalysisController::class, 'report']);
    Route::get('rows', [AnalysisController::class, 'index']);
    Route::get('rows/{id}/evidence', [AnalysisController::class, 'evidence'])->whereNumber('id');
    Route::get('imports', [AnalysisController::class, 'imports'])->middleware('permission:business.analysis.import');
    Route::post('imports', [AnalysisController::class, 'import'])->middleware('permission:business.analysis.import');
    Route::get('export', [AnalysisController::class, 'export'])->middleware('permission:business.analysis.export');
});
