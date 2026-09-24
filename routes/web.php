<?php

use App\Modules\Identity\Domain\Authorization\PermissionCatalog;
use App\Modules\Identity\Presentation\Http\Controllers\SessionController;
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
