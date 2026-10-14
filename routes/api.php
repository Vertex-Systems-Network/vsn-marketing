<?php

use App\Modules\Consent\Presentation\Http\Controllers\OneClickUnsubscribeController;
use App\Modules\Core\Presentation\Http\Controllers\HealthController;
use App\Modules\Core\Presentation\Http\Controllers\MetricsController;
use App\Modules\Core\Presentation\Http\Controllers\RuntimeStatusController;
use App\Modules\Identity\Presentation\Http\Controllers\ScimDeactivationController;
use Illuminate\Support\Facades\Route;

Route::post('/v1/unsubscribe/one-click', [OneClickUnsubscribeController::class, 'store'])
    ->name('unsubscribe.one-click');

Route::middleware('throttle:20,1')->patch('/scim/v2/workspaces/{workspace}/Users/{user}', ScimDeactivationController::class)
    ->name('enterprise.scim.deactivate');

Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');

Route::middleware(['operations.auth', 'throttle:operations'])->group(function (): void {
    Route::get('/runtime', RuntimeStatusController::class)->name('runtime.status');
    Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');
    Route::get('/metrics', MetricsController::class)->name('metrics');
});
