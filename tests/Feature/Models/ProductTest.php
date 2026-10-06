<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

it('casts stock and price to integers and status to its backed enum', function () {
    $product = Product::factory()->create(['price_minor' => 1250, 'stock_quantity' => 10]);

    $product->refresh();

    expect($product->price_minor)->toBe(1250);
    expect($product->stock_quantity)->toBe(10);
    expect($product->status)->toBe(ProductStatus::Active);
});

it('normalizes SKU before Eloquent writes', function () {
    $product = Product::factory()->create(['sku' => '  sku-one  ']);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'sku' => 'SKU-ONE']);
});

it('rejects duplicate SKUs at the database even when bypassing model normalization', function (string $sku) {
    Product::factory()->create(['sku' => 'SKU-ONE']);
    $attributes = Product::factory()->make()->getAttributes();
    $attributes['sku'] = $sku;

    expect(fn () => DB::transaction(fn () => DB::table('products')->insert($attributes)))
        ->toThrow(QueryException::class, 'products_sku_normalized_unique');

    $this->assertDatabaseCount('products', 1);
})->with(['exact' => 'SKU-ONE', 'lowercase' => 'sku-one', 'padded lowercase' => ' sku-one ']);

it('rejects invalid scalar values at the database boundary', function (string $field, mixed $value, string $constraint) {
    $attributes = Product::factory()->make()->getAttributes();
    $attributes[$field] = $value;

    expect(fn () => DB::transaction(fn () => DB::table('products')->insert($attributes)))
        ->toThrow(QueryException::class, $constraint);

    $this->assertDatabaseCount('products', 0);
})->with([
    'negative price' => ['price_minor', -1, 'products_price_minor_non_negative'],
    'negative stock' => ['stock_quantity', -1, 'products_stock_quantity_non_negative'],
    'invalid status' => ['status', 'archived', 'products_status_allowed'],
    'empty SKU' => ['sku', '', 'products_sku_not_blank'],
    'blank SKU' => ['sku', '   ', 'products_sku_not_blank'],
    'null name' => ['name', null, 'not-null constraint'],
    'null SKU' => ['sku', null, 'not-null constraint'],
    'null price' => ['price_minor', null, 'not-null constraint'],
    'null stock' => ['stock_quantity', null, 'not-null constraint'],
    'null status' => ['status', null, 'not-null constraint'],
]);

it('stores zero and maximum signed bigint prices without rounding', function (int $price) {
    $product = Product::factory()->outOfStock()->create(['price_minor' => $price]);

    expect($product->fresh()->price_minor)->toBe($price);
})->with(['zero' => 0, 'bigint maximum' => PHP_INT_MAX]);
