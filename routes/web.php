<?php

use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Presentation\Http\Controllers\SessionController;
use App\Modules\Publishing\Presentation\Http\Controllers\PublishingBulkApprovalController;
use App\Modules\Publishing\Presentation\Http\Controllers\PublishingOperatorController;
use App\Modules\Segmentation\Presentation\Http\Controllers\SegmentProposalController;
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
    ->post('/workspaces/{workspace}/publishing/approvals/bulk', PublishingBulkApprovalController::class)
    ->name('publishing.approvals.bulk');

Route::middleware(['auth', 'tenant', 'workspace.permission:'.PermissionCatalog::CONTACT_READ])
    ->get('/workspaces/{workspace}/segments', [SegmentProposalController::class, 'index'])
    ->name('segments.operator');

Route::middleware([
    'auth',
    'tenant',
    'workspace.permission:'.PermissionCatalog::CONTACT_READ,
    'workspace.permission:'.PermissionCatalog::AI_EXECUTE,
])
    ->post('/workspaces/{workspace}/segments/proposals', [SegmentProposalController::class, 'propose'])
    ->name('segments.proposals');

Route::middleware([
    'auth',
    'tenant',
    'workspace.permission:'.PermissionCatalog::CONTACT_READ,
    'workspace.permission:'.PermissionCatalog::CONTACT_WRITE,
])
    ->post('/workspaces/{workspace}/segments/versions', [SegmentProposalController::class, 'store'])
    ->name('segments.versions.store');
