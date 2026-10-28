<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChangesController;
use App\Http\Controllers\OccupancyController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'showLogin'])->middleware('guest')->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('guest');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/occupancy');

    Route::get('/occupancy', [OccupancyController::class, 'index'])->name('occupancy.index');
    Route::get('/occupancy/{serial}', [OccupancyController::class, 'show'])->name('occupancy.show');

    // Detected rank/price changes and what happened either side of them.
    Route::get('/changes', [ChangesController::class, 'index'])->name('changes.index');

    // "Pull Now" button. POST so a stray browser refresh cannot re-trigger a sync.
    Route::post('/sync', [OccupancyController::class, 'sync'])->name('occupancy.sync');
});
