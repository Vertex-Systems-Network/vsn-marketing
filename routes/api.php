<?php

use App\Modules\Consent\Presentation\Http\Controllers\OneClickUnsubscribeController;
use App\Modules\Contacts\Presentation\Http\Controllers\Api\V1\ContactController;
use App\Modules\Core\Presentation\Http\Controllers\HealthController;
use App\Modules\Core\Presentation\Http\Controllers\MetricsController;
use App\Modules\Core\Presentation\Http\Controllers\RuntimeStatusController;
use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\AccessTokenController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\MeController;
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

// Product API v1 (docs/product/API-V1.md). Every route here must appear in openapi/v1.json.
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::middleware('throttle:login')->post('/auth/tokens', [AccessTokenController::class, 'store'])->name('auth.tokens.store');

    Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
        Route::delete('/auth/tokens/current', [AccessTokenController::class, 'destroy'])->name('auth.tokens.destroy');
        Route::get('/me', [MeController::class, 'show'])->name('me.show');
        Route::get('/me/workspaces', [MeController::class, 'workspaces'])->name('me.workspaces');

        Route::middleware(['tenant', 'workspace.permission:'.PermissionCatalog::CONTACT_READ])->group(function (): void {
            Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
            Route::get('/contacts/{contact}', [ContactController::class, 'show'])->whereUuid('contact')->name('contacts.show');
        });
    });
});
