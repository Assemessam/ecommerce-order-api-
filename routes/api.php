<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/{id}', [ProductController::class, 'show'])->whereNumber('id')->name('products.show');

Route::prefix('auth')->name('auth.')->controller(AuthController::class)->group(function (): void {
    Route::post('/register', 'register')->middleware('throttle:auth-register')->name('register');
    Route::post('/login', 'login')->middleware('throttle:auth-login')->name('login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', 'logout')->name('logout');
        Route::get('/me', 'me')->name('me');
    });
});
