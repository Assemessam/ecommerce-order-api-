<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CartPromotionController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/{id}', [ProductController::class, 'show'])->whereNumber('id')->name('products.show');

Route::middleware('auth:sanctum')->prefix('cart')->name('cart.')->controller(CartController::class)->group(function (): void {
    Route::get('/', 'show')->name('show');
    Route::post('/items', 'store')->name('items.store');
    Route::patch('/items/{id}', 'update')->whereNumber('id')->name('items.update');
    Route::delete('/items/{id}', 'destroy')->whereNumber('id')->name('items.destroy');
});

Route::middleware('auth:sanctum')->prefix('cart/promotion')->name('cart.promotion.')->controller(CartPromotionController::class)->group(function (): void {
    Route::post('/', 'store')->name('store');
    Route::delete('/', 'destroy')->name('destroy');
});

Route::prefix('auth')->name('auth.')->controller(AuthController::class)->group(function (): void {
    Route::post('/register', 'register')->middleware('throttle:auth-register')->name('register');
    Route::post('/login', 'login')->middleware('throttle:auth-login')->name('login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', 'logout')->name('logout');
        Route::get('/me', 'me')->name('me');
    });
});
