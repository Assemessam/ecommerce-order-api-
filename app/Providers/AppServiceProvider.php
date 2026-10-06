<?php

namespace App\Providers;

use App\Contracts\Repositories\CartRepositoryInterface;
use App\Contracts\Repositories\OrderOutboxRepositoryInterface;
use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\PromotionRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Http\RateLimiting\ApiRateLimiters;
use App\Repositories\Eloquent\EloquentCartRepository;
use App\Repositories\Eloquent\EloquentOrderOutboxRepository;
use App\Repositories\Eloquent\EloquentOrderRepository;
use App\Repositories\Eloquent\EloquentProductRepository;
use App\Repositories\Eloquent\EloquentPromotionRepository;
use App\Repositories\Eloquent\EloquentUserRepository;
use App\Services\Product\ProductCatalogueCache;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(ProductCatalogueCache::class);
        $this->app->bind(OrderRepositoryInterface::class, EloquentOrderRepository::class);
        $this->app->bind(OrderOutboxRepositoryInterface::class, EloquentOrderOutboxRepository::class);
        $this->app->bind(CartRepositoryInterface::class, EloquentCartRepository::class);
        $this->app->bind(PromotionRepositoryInterface::class, EloquentPromotionRepository::class);
        $this->app->bind(ProductRepositoryInterface::class, EloquentProductRepository::class);
        $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn (): Password => Password::min(12)->mixedCase()->numbers()->symbols());

        ApiRateLimiters::register();
    }
}
