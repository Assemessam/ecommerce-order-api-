<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('seeds a repeatable mixed catalogue without changing customers or existing products', function () {
    $customer = User::factory()->create();
    $originalCustomer = $customer->fresh()->getAttributes();
    $existing = Product::factory()->create(['sku' => 'DEMO-KEYBOARD', 'price_minor' => 7777, 'stock_quantity' => 3]);
    $unrelated = Product::factory()->create(['sku' => 'CUSTOM-PRODUCT']);
    $originalUnrelated = $unrelated->fresh()->getAttributes();

    $this->seed(ProductSeeder::class);
    $this->seed(ProductSeeder::class);

    $this->assertDatabaseCount('products', 7);
    $this->assertDatabaseCount('users', 1);
    expect($customer->fresh()->getAttributes())->toBe($originalCustomer);
    expect($unrelated->fresh()->getAttributes())->toBe($originalUnrelated);
    $this->assertDatabaseHas('products', ['id' => $existing->id, 'price_minor' => 7777, 'stock_quantity' => 3]);
    expect(Product::query()->where('status', ProductStatus::Active)->count())->toBe(5);
    expect(Product::query()->where('status', ProductStatus::Inactive)->count())->toBe(2);
    expect(Product::query()->where('stock_quantity', 0)->count())->toBe(2);
});
