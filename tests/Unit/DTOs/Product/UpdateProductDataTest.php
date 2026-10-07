<?php

use App\DTOs\Product\UpdateProductData;
use App\Enums\ProductStatus;

it('represents an empty patch without persistence attributes or an inventory command', function () {
    $data = UpdateProductData::fromArray([]);

    expect($data->toPersistenceArray())->toBe([]);
    expect($data->has('description'))->toBeFalse();
    expect($data->hasStockAdjustment())->toBeFalse();
});

it('distinguishes an omitted description from an explicit null clear', function () {
    $omitted = UpdateProductData::fromArray(['name' => 'Updated']);
    $cleared = UpdateProductData::fromArray(['description' => null]);

    expect($omitted->description)->toBeNull();
    expect($omitted->has('description'))->toBeFalse();
    expect($omitted->toPersistenceArray())->toBe(['name' => 'Updated']);
    expect($cleared->description)->toBeNull();
    expect($cleared->has('description'))->toBeTrue();
    expect($cleared->toPersistenceArray())->toBe(['description' => null]);
});

it('maps provided editable fields while carrying the stock delta separately', function () {
    $data = UpdateProductData::fromArray([
        'name' => 'Updated', 'sku' => 'UPDATED', 'description' => 'Updated description',
        'price_minor' => 200, 'status' => 'inactive', 'stock_adjustment' => -3,
    ]);

    expect($data->status)->toBe(ProductStatus::Inactive);
    expect($data->stockAdjustment)->toBe(-3);
    expect($data->hasStockAdjustment())->toBeTrue();
    expect($data->toPersistenceArray())->toBe([
        'name' => 'Updated', 'sku' => 'UPDATED', 'description' => 'Updated description',
        'price_minor' => 200, 'status' => 'inactive',
    ]);
});

it('preserves zero and maximum integer prices in a partial update', function (int $price) {
    $data = UpdateProductData::fromArray(['price_minor' => $price]);

    expect($data->priceMinor)->toBe($price);
    expect($data->has('price_minor'))->toBeTrue();
    expect($data->toPersistenceArray())->toBe(['price_minor' => $price]);
})->with(['zero' => 0, 'maximum' => PHP_INT_MAX]);

it('tracks an integer zero stock command without duplicating HTTP nonzero validation', function () {
    $data = UpdateProductData::fromArray(['stock_adjustment' => 0]);

    expect($data->stockAdjustment)->toBe(0);
    expect($data->hasStockAdjustment())->toBeTrue();
    expect($data->toPersistenceArray())->toBe([]);
});

it('ignores absolute stock and forged persistence fields outside the application command', function () {
    $data = UpdateProductData::fromArray(['stock_quantity' => 100, 'id' => 999, 'created_at' => '2000-01-01']);

    expect($data->has('stock_quantity'))->toBeFalse();
    expect($data->has('id'))->toBeFalse();
    expect($data->toPersistenceArray())->toBe([]);
});

it('rejects malformed supplied fields without treating null as omission or coercing values', function (string $field, mixed $value) {
    expect(fn () => UpdateProductData::fromArray([$field => $value]))->toThrow(InvalidArgumentException::class);
})->with([
    'null name' => ['name', null], 'numeric name' => ['name', 123],
    'null SKU' => ['sku', null], 'numeric SKU' => ['sku', 123],
    'null price' => ['price_minor', null], 'string price' => ['price_minor', '100'],
    'float price' => ['price_minor', 100.0], 'boolean price' => ['price_minor', false],
    'numeric description' => ['description', 123], 'array description' => ['description', []],
    'null status' => ['status', null], 'array status' => ['status', []],
    'null stock adjustment' => ['stock_adjustment', null], 'string stock adjustment' => ['stock_adjustment', '2'],
    'float stock adjustment' => ['stock_adjustment', 2.0], 'boolean stock adjustment' => ['stock_adjustment', true],
]);

it('rejects an unknown supplied status instead of persisting an unchecked enum string', function () {
    expect(fn () => UpdateProductData::fromArray(['status' => 'deleted']))->toThrow(ValueError::class);
});
