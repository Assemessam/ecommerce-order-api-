<?php

use App\DTOs\Promotion\PromotionQuery;

it('uses existing pagination defaults with no active filter', function () {
    $query = PromotionQuery::fromArray([]);

    expect($query->page)->toBe(1);
    expect($query->perPage)->toBe(15);
    expect($query->isActive)->toBeNull();
});

it('normalizes validated integer representations without changing pagination values', function (mixed $input, int $expected) {
    $query = PromotionQuery::fromArray(['page' => $input, 'per_page' => $input]);

    expect($query->page)->toBe($expected);
    expect($query->perPage)->toBe($expected);
})->with([
    'integer' => [2, 2], 'numeric string' => ['2', 2], 'plus-prefixed string' => ['+2', 2],
    'padded string' => [' 2 ', 2], 'integral float' => [2.0, 2], 'true flag' => [true, 1],
]);

it('preserves each validated active filter representation', function (mixed $input, bool $expected) {
    $query = PromotionQuery::fromArray(['is_active' => $input]);

    expect($query->isActive)->toBe($expected);
})->with([
    'true' => [true, true], 'false' => [false, false],
    'integer one' => [1, true], 'integer zero' => [0, false],
    'string one' => ['1', true], 'string zero' => ['0', false],
]);

it('rejects malformed pagination values rather than manufacturing defaults', function (string $field, mixed $value) {
    expect(fn () => PromotionQuery::fromArray([$field => $value]))->toThrow(InvalidArgumentException::class);
})->with(['page', 'per_page'])->with([
    'null' => null, 'false' => false, 'array' => [[]], 'decimal string' => '2.5',
    'fractional float' => 2.5, 'leading zero string' => '02', 'empty string' => '',
]);

it('rejects unvalidated active flags instead of treating arbitrary values as truthy', function (mixed $input) {
    expect(fn () => PromotionQuery::fromArray(['is_active' => $input]))->toThrow(InvalidArgumentException::class);
})->with([
    'null' => null, 'true word' => 'true', 'false word' => 'false', 'integer two' => 2,
    'float zero' => 0.0, 'array' => [[]], 'empty string' => '',
]);
