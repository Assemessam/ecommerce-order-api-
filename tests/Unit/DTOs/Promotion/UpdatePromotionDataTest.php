<?php

use App\DTOs\Promotion\UpdatePromotionData;
use App\Enums\PromotionType;

it('maps an empty update to no supplied fields or persistence changes', function () {
    $data = UpdatePromotionData::fromArray([]);

    expect($data->toPersistenceArray())->toBe([]);
    expect($data->has('code'))->toBeFalse();
    expect($data->has('maximum_discount_minor'))->toBeFalse();
    expect($data->has('starts_at'))->toBeFalse();
    expect($data->has('is_active'))->toBeFalse();
});

it('distinguishes omitted nullable fields from explicit clears', function (string $field) {
    $omitted = UpdatePromotionData::fromArray([]);
    $cleared = UpdatePromotionData::fromArray([$field => null]);

    expect($omitted->has($field))->toBeFalse();
    expect($omitted->toPersistenceArray())->toBe([]);
    expect($cleared->has($field))->toBeTrue();
    expect($cleared->toPersistenceArray())->toBe([$field => null]);
})->with(['maximum_discount_minor', 'starts_at', 'expires_at', 'global_usage_limit', 'per_customer_usage_limit']);

it('preserves false activation and zero minimum as supplied changes', function () {
    $data = UpdatePromotionData::fromArray(['minimum_cart_amount_minor' => 0, 'is_active' => false]);

    expect($data->minimumCartAmountMinor)->toBe(0);
    expect($data->isActive)->toBeFalse();
    expect($data->has('minimum_cart_amount_minor'))->toBeTrue();
    expect($data->has('is_active'))->toBeTrue();
    expect($data->toPersistenceArray())->toBe(['minimum_cart_amount_minor' => 0, 'is_active' => false]);
});

it('maps every typed promotion change and ignores unknown fields', function () {
    $data = UpdatePromotionData::fromArray([
        'code' => 'UPDATED', 'type' => 'fixed', 'value' => PHP_INT_MAX,
        'minimum_cart_amount_minor' => 10000, 'maximum_discount_minor' => 2500,
        'starts_at' => '2026-10-06T15:00:00.123456+03:00', 'expires_at' => '2026-11-06T12:00:00+00:00',
        'global_usage_limit' => 10, 'per_customer_usage_limit' => 2, 'is_active' => true,
        'id' => 9000, 'redemptions_count' => 9, 'user_id' => 9000,
    ]);

    expect($data->type)->toBe(PromotionType::Fixed);
    expect($data->has('id'))->toBeFalse();
    expect($data->has('redemptions_count'))->toBeFalse();
    expect($data->toPersistenceArray())->toBe([
        'code' => 'UPDATED', 'type' => 'fixed', 'value' => PHP_INT_MAX,
        'minimum_cart_amount_minor' => 10000, 'maximum_discount_minor' => 2500,
        'starts_at' => '2026-10-06T15:00:00.123456+03:00', 'expires_at' => '2026-11-06T12:00:00+00:00',
        'global_usage_limit' => 10, 'per_customer_usage_limit' => 2, 'is_active' => true,
    ]);
});

it('rejects malformed supplied promotion fields without scalar coercion', function (string $field, mixed $value) {
    expect(fn () => UpdatePromotionData::fromArray([$field => $value]))->toThrow(InvalidArgumentException::class);
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

it('rejects an unknown supplied promotion type', function () {
    expect(fn () => UpdatePromotionData::fromArray(['type' => 'unknown']))->toThrow(ValueError::class);
});
