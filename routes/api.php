<?php

use App\Http\Controllers\Api\AdminProductController;
use App\Http\Controllers\Api\AdminPromotionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CartPromotionController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/products', [AdminProductController::class, 'index'])->can('viewAny', Product::class)->name('products.index');
    Route::get('/products/{id}', [AdminProductController::class, 'show'])->whereNumber('id')->can('view', Product::class)->name('products.show');
    Route::post('/products', [AdminProductController::class, 'store'])->can('create', Product::class)->name('products.store');
    Route::patch('/products/{id}', [AdminProductController::class, 'update'])->whereNumber('id')->can('update', Product::class)->name('products.update');

    Route::get('/promotions', [AdminPromotionController::class, 'index'])->can('viewAny', Promotion::class)->name('promotions.index');
    Route::get('/promotions/{id}', [AdminPromotionController::class, 'show'])->whereNumber('id')->can('view', Promotion::class)->name('promotions.show');
    Route::post('/promotions', [AdminPromotionController::class, 'store'])->can('create', Promotion::class)->name('promotions.store');
    Route::patch('/promotions/{id}', [AdminPromotionController::class, 'update'])->whereNumber('id')->can('update', Promotion::class)->name('promotions.update');
});

Route::get('/health', HealthController::class)->name('health');

Route::post('/checkout', CheckoutController::class)->middleware('auth:sanctum')->name('checkout');

Route::middleware('auth:sanctum')->prefix('orders')->name('orders.')->controller(OrderController::class)->group(function (): void {
    Route::get('/', 'index')->name('index');
    Route::get('/{id}', 'show')->whereNumber('id')->name('show');
    Route::post('/{id}/cancel', 'cancel')->whereNumber('id')->name('cancel');
});

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
