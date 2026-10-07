<?php

use App\DTOs\Promotion\CreatePromotionData;
use App\Enums\PromotionType;

it('maps typed promotion inputs without retaining forged attributes or changing date strings', function () {
    $data = CreatePromotionData::fromArray([
        'code' => 'SAVE', 'type' => 'percentage', 'value' => 2000,
        'minimum_cart_amount_minor' => 10000, 'maximum_discount_minor' => 2500,
        'starts_at' => '2026-10-06T15:00:00.123456+03:00', 'expires_at' => '2026-11-06T12:00:00+00:00',
        'global_usage_limit' => 10, 'per_customer_usage_limit' => 2, 'is_active' => false,
        'id' => 9000, 'redemptions_count' => 9, 'user_id' => 9000,
    ]);

    expect($data->type)->toBe(PromotionType::Percentage);
    expect($data->toPersistenceArray())->toBe([
        'code' => 'SAVE', 'type' => 'percentage', 'value' => 2000,
        'minimum_cart_amount_minor' => 10000, 'maximum_discount_minor' => 2500,
        'starts_at' => '2026-10-06T15:00:00.123456+03:00', 'expires_at' => '2026-11-06T12:00:00+00:00',
        'global_usage_limit' => 10, 'per_customer_usage_limit' => 2, 'is_active' => false,
    ]);
});

it('leaves every omitted optional promotion field out of persistence attributes', function () {
    $data = CreatePromotionData::fromArray(['code' => 'DEFAULT', 'type' => 'fixed', 'value' => 1]);

    expect($data->minimumCartAmountMinor)->toBeNull();
    expect($data->isActive)->toBeNull();
    expect($data->toPersistenceArray())->toBe(['code' => 'DEFAULT', 'type' => 'fixed', 'value' => 1]);
});

it('retains explicit null caps dates and usage limits in creation attributes', function () {
    $data = CreatePromotionData::fromArray([
        'code' => 'UNLIMITED', 'type' => 'fixed', 'value' => 1,
        'maximum_discount_minor' => null, 'starts_at' => null, 'expires_at' => null,
        'global_usage_limit' => null, 'per_customer_usage_limit' => null,
    ]);

    expect($data->toPersistenceArray())->toBe([
        'code' => 'UNLIMITED', 'type' => 'fixed', 'value' => 1,
        'maximum_discount_minor' => null, 'starts_at' => null, 'expires_at' => null,
        'global_usage_limit' => null, 'per_customer_usage_limit' => null,
    ]);
});

it('preserves zero minimum false activation and maximum integer financial amounts', function () {
    $data = CreatePromotionData::fromArray([
        'code' => 'MAXIMUM', 'type' => 'fixed', 'value' => PHP_INT_MAX,
        'minimum_cart_amount_minor' => 0, 'maximum_discount_minor' => PHP_INT_MAX, 'is_active' => false,
    ]);

    expect($data->type)->toBe(PromotionType::Fixed);
    expect($data->toPersistenceArray())->toBe([
        'code' => 'MAXIMUM', 'type' => 'fixed', 'value' => PHP_INT_MAX,
        'minimum_cart_amount_minor' => 0, 'maximum_discount_minor' => PHP_INT_MAX, 'is_active' => false,
    ]);
});

it('rejects missing required promotion fields instead of manufacturing values', function (string $field) {
    $input = ['code' => 'SAVE', 'type' => 'fixed', 'value' => 1];
    unset($input[$field]);

    expect(fn () => CreatePromotionData::fromArray($input))->toThrow(InvalidArgumentException::class);
})->with(['code', 'type', 'value']);

it('rejects malformed promotion field types without scalar coercion', function (string $field, mixed $value) {
    $input = ['code' => 'SAVE', 'type' => 'fixed', 'value' => 1];
    $input[$field] = $value;

    expect(fn () => CreatePromotionData::fromArray($input))->toThrow(InvalidArgumentException::class);
})->with([
    'numeric code' => ['code', 123], 'null code' => ['code', null],
    'null type' => ['type', null], 'array type' => ['type', []],
    'string value' => ['value', '1'], 'float value' => ['value', 1.0],
    'boolean value' => ['value', false], 'null value' => ['value', null],
    'null minimum' => ['minimum_cart_amount_minor', null],
    'string minimum' => ['minimum_cart_amount_minor', '0'], 'float minimum' => ['minimum_cart_amount_minor', 0.0],
    'string cap' => ['maximum_discount_minor', '1'], 'float cap' => ['maximum_discount_minor', 1.0],
    'boolean global limit' => ['global_usage_limit', false], 'string global limit' => ['global_usage_limit', '2'],
    'string customer limit' => ['per_customer_usage_limit', '1'], 'array customer limit' => ['per_customer_usage_limit', []],
    'numeric start' => ['starts_at', 123], 'array expiry' => ['expires_at', []],
    'string active flag' => ['is_active', 'false'], 'integer active flag' => ['is_active', 0],
    'null active flag' => ['is_active', null],
]);

it('rejects an unknown promotion type instead of carrying an unchecked enum string', function () {
    expect(fn () => CreatePromotionData::fromArray(['code' => 'SAVE', 'type' => 'unknown', 'value' => 1]))
        ->toThrow(ValueError::class);
});
