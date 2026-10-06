<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::prefix('auth')->name('auth.')->controller(AuthController::class)->group(function (): void {
    Route::post('/register', 'register')->middleware('throttle:auth-register')->name('register');
    Route::post('/login', 'login')->middleware('throttle:auth-login')->name('login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', 'logout')->name('logout');
        Route::get('/me', 'me')->name('me');
    });
});
