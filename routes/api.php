<?php

use App\Modules\Core\Presentation\Http\Controllers\HealthController;
use App\Modules\Core\Presentation\Http\Controllers\MetricsController;
use App\Modules\Core\Presentation\Http\Controllers\RuntimeStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');

Route::middleware(['throttle:operations', 'operations.auth'])->group(function (): void {
    Route::get('/runtime', RuntimeStatusController::class)->name('runtime.status');
    Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');
    Route::get('/metrics', MetricsController::class)->name('metrics');
});
