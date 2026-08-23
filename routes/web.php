<?php

use App\Modules\Identity\Presentation\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware('guest')->post('/auth/login', [SessionController::class, 'store'])->name('auth.login');
Route::middleware('auth')->post('/auth/logout', [SessionController::class, 'destroy'])->name('auth.logout');
