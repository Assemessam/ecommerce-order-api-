<?php

use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Database\Seeders\PromotionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('seeds seven realistic examples repeatably while preserving existing promotions customers and products', function () {
    $this->freezeTime();
    $product = Product::factory()->create(['price_minor' => 123, 'stock_quantity' => 7]);
    $user = User::factory()->create();
    $existing = Promotion::factory()->fixed(999)->inactive()->create(['code' => 'SAVE15']);

    $this->seed(PromotionSeeder::class);
    $this->seed(PromotionSeeder::class);

    $this->assertDatabaseCount('promotions', 7);
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('products', 1);
    $this->assertDatabaseCount('promotion_redemptions', 0);
    $this->assertModelExists($user);
    expect($existing->fresh()->value)->toBe(999);
    expect($existing->fresh()->is_active)->toBeFalse();
    expect($product->fresh()->price_minor)->toBe(123);
    expect($product->fresh()->stock_quantity)->toBe(7);
    $this->assertDatabaseHas('promotions', ['code' => 'SUMMER20', 'value' => 2000, 'minimum_cart_amount_minor' => 10000, 'maximum_discount_minor' => 5000]);
    $this->assertDatabaseHas('promotions', ['code' => 'WELCOME10', 'value' => 1000]);
    $this->assertDatabaseHas('promotions', ['code' => 'LIMITED5', 'value' => 500, 'global_usage_limit' => 5, 'per_customer_usage_limit' => 1]);
    $this->assertDatabaseHas('promotions', ['code' => 'INACTIVE10', 'is_active' => false]);
    expect(Promotion::query()->where('code', 'FUTURE10')->firstOrFail()->starts_at->isFuture())->toBeTrue();
    expect(Promotion::query()->where('code', 'EXPIRED10')->firstOrFail()->expires_at->isPast())->toBeTrue();
});
