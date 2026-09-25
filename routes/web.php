<?php

use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Presentation\Http\Controllers\SessionController;
use App\Modules\Publishing\Presentation\Http\Controllers\PublishingOperatorApprovalController;
use App\Modules\Publishing\Presentation\Http\Controllers\PublishingOperatorController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['guest', 'throttle:login'])
    ->post('/auth/login', [SessionController::class, 'store'])
    ->name('auth.login');
Route::middleware('auth')->post('/auth/logout', [SessionController::class, 'destroy'])->name('auth.logout');

Route::middleware(['auth', 'tenant', 'workspace.permission:'.PermissionCatalog::CAMPAIGN_READ])
    ->get('/workspaces/{workspace}/publishing', PublishingOperatorController::class)
    ->name('publishing.operator');

Route::middleware(['auth', 'tenant', 'workspace.permission:'.PermissionCatalog::CAMPAIGN_APPROVE])
    ->prefix('/workspaces/{workspace}/publishing/campaigns/{campaign}/approval')
    ->group(function (): void {
        Route::post('/approve', [PublishingOperatorApprovalController::class, 'approve'])
            ->name('publishing.operator.approval.approve');
        Route::post('/reject', [PublishingOperatorApprovalController::class, 'reject'])
            ->name('publishing.operator.approval.reject');
        Route::post('/revoke', [PublishingOperatorApprovalController::class, 'revoke'])
            ->name('publishing.operator.approval.revoke');
    });
