<?php

use App\Http\Controllers\Api\PuttSyncController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\StatsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LogController::class, 'index'])->name('log');
Route::get('/stats', [StatsController::class, 'index'])->name('stats');
Route::get('/stats/compare', [StatsController::class, 'compare'])->name('stats.compare');

Route::get('/sessions', [SessionController::class, 'index'])->name('sessions.index');
Route::get('/sessions/{session}', [SessionController::class, 'show'])->name('sessions.show');
Route::delete('/sessions/{session}', [SessionController::class, 'destroy'])->name('sessions.destroy');
Route::delete('/sessions/{session}/putts/{putt}', [SessionController::class, 'destroyPutt'])->name('sessions.putts.destroy');

Route::prefix('api')->name('api.')->group(function (): void {
    Route::post('/putts/sync', [PuttSyncController::class, 'store'])->name('putts.sync');
    Route::get('/progress', [PuttSyncController::class, 'progress'])->name('progress');
    Route::delete('/putts/{uuid}', [PuttSyncController::class, 'destroy'])->name('putts.destroy');
});
