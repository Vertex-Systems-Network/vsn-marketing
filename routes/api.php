<?php

use App\Modules\Core\Presentation\Http\Controllers\HealthController;
use App\Modules\Core\Presentation\Http\Controllers\MetricsController;
use App\Modules\Core\Presentation\Http\Controllers\RuntimeStatusController;
use App\Modules\Connectors\Presentation\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/runtime', RuntimeStatusController::class)->name('runtime.status');
Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');
Route::get('/metrics', MetricsController::class)->name('metrics');

// Generic webhook endpoint for connectors. The {connector} parameter is the connector name/identifier.
Route::post('/webhook/{connector}', [WebhookController::class, 'handle'])->name('webhook.handle');
