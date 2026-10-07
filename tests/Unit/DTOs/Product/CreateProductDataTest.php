<?php

use App\DTOs\Product\CreateProductData;
use App\Enums\ProductStatus;

it('maps typed product inputs and converts status without retaining forged attributes', function () {
    $data = CreateProductData::fromArray([
        'name' => 'Product', 'sku' => 'SKU', 'price_minor' => 1899, 'description' => 'Description',
        'stock_quantity' => 7, 'status' => 'inactive', 'id' => 999, 'stock_adjustment' => 100,
    ]);

    expect($data->status)->toBe(ProductStatus::Inactive);
    expect($data->toPersistenceArray())->toBe([
        'name' => 'Product', 'sku' => 'SKU', 'price_minor' => 1899, 'description' => 'Description',
        'stock_quantity' => 7, 'status' => 'inactive',
    ]);
});

it('leaves absent optional fields out of persistence attributes for database defaults', function () {
    $data = CreateProductData::fromArray(['name' => 'Product', 'sku' => 'SKU', 'price_minor' => 100]);

    expect($data->stockQuantity)->toBeNull();
    expect($data->status)->toBeNull();
    expect($data->toPersistenceArray())->toBe([
        'name' => 'Product', 'sku' => 'SKU', 'price_minor' => 100,
    ]);
});

it('preserves explicit null descriptions and zero inventory overrides', function () {
    $data = CreateProductData::fromArray([
        'name' => 'Product', 'sku' => 'SKU', 'price_minor' => 100,
        'description' => null, 'stock_quantity' => 0, 'status' => 'active',
    ]);

    expect($data->description)->toBeNull();
    expect($data->stockQuantity)->toBe(0);
    expect($data->status)->toBe(ProductStatus::Active);
    expect($data->toPersistenceArray())->toBe([
        'name' => 'Product', 'sku' => 'SKU', 'price_minor' => 100,
        'description' => null, 'stock_quantity' => 0, 'status' => 'active',
    ]);
});

it('keeps zero and maximum integer prices exact', function (int $price) {
    $data = CreateProductData::fromArray(['name' => 'Product', 'sku' => 'SKU', 'price_minor' => $price]);

    expect($data->priceMinor)->toBe($price);
    expect($data->toPersistenceArray()['price_minor'])->toBe($price);
})->with(['zero' => 0, 'maximum' => PHP_INT_MAX]);

it('rejects missing required fields instead of manufacturing product values', function (string $field) {
    $input = ['name' => 'Product', 'sku' => 'SKU', 'price_minor' => 100];
    unset($input[$field]);

    expect(fn () => CreateProductData::fromArray($input))->toThrow(InvalidArgumentException::class);
})->with(['name', 'sku', 'price_minor']);

it('rejects malformed product field types without scalar coercion', function (string $field, mixed $value) {
    $input = ['name' => 'Product', 'sku' => 'SKU', 'price_minor' => 100];
    $input[$field] = $value;

    expect(fn () => CreateProductData::fromArray($input))->toThrow(InvalidArgumentException::class);
})->with([
    'numeric name' => ['name', 123], 'null name' => ['name', null],
    'numeric SKU' => ['sku', 123], 'null SKU' => ['sku', null],
    'string price' => ['price_minor', '100'], 'float price' => ['price_minor', 100.0],
    'null price' => ['price_minor', null], 'boolean price' => ['price_minor', false],
    'numeric description' => ['description', 123], 'array description' => ['description', []],
    'string stock' => ['stock_quantity', '7'], 'float stock' => ['stock_quantity', 7.0],
    'null stock' => ['stock_quantity', null], 'boolean stock' => ['stock_quantity', true],
    'null status' => ['status', null], 'array status' => ['status', []],
]);

it('rejects an unknown status instead of carrying an unchecked enum string', function () {
    expect(fn () => CreateProductData::fromArray([
        'name' => 'Product', 'sku' => 'SKU', 'price_minor' => 100, 'status' => 'deleted',
    ]))->toThrow(ValueError::class);
});
