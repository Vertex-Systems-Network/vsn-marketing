<?php

use App\Modules\Core\Presentation\Http\Controllers\RuntimeStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/runtime', RuntimeStatusController::class)->name('runtime.status');
