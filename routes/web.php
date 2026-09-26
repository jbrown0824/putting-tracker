<?php

use App\Http\Controllers\Api\PuttSyncController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\ChallengeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\PutterController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StatsController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/log', [LogController::class, 'index'])->name('log');
    Route::get('/stats', [StatsController::class, 'index'])->name('stats');
    Route::get('/stats/compare', [StatsController::class, 'compare'])->name('stats.compare');

    Route::get('/sessions', [SessionController::class, 'index'])->name('sessions.index');
    Route::get('/sessions/{session}', [SessionController::class, 'show'])->name('sessions.show');
    Route::delete('/sessions/{session}', [SessionController::class, 'destroy'])->name('sessions.destroy');
    Route::delete('/sessions/{session}/putts/{putt}', [SessionController::class, 'destroyPutt'])->name('sessions.putts.destroy');

    Route::resource('challenges', ChallengeController::class);
    Route::patch('/challenges/{challenge}/archive', [ChallengeController::class, 'archive'])->name('challenges.archive');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::patch('/settings/account', [SettingsController::class, 'updateAccount'])->name('settings.account');
    Route::resource('putters', PutterController::class)->except(['index', 'show']);

    Route::prefix('api')->name('api.')->group(function (): void {
        Route::post('/putts/sync', [PuttSyncController::class, 'store'])->name('putts.sync');
        Route::get('/progress', [PuttSyncController::class, 'progress'])->name('progress');
        Route::delete('/putts/{uuid}', [PuttSyncController::class, 'destroy'])->name('putts.destroy');
    });
});
