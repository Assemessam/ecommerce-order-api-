<?php

use App\Enums\PromotionType;
use App\Models\Cart;
use App\Models\Promotion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

it('stores canonical codes and rejects equivalent model writes', function () {
    $promotion = Promotion::factory()->create(['code' => " \tsummer20\n"]);

    expect($promotion->code)->toBe('SUMMER20');
    expect(fn () => DB::transaction(fn () => Promotion::factory()->create(['code' => ' summer20 '])))
        ->toThrow(QueryException::class, 'promotions_code_unique');
    $this->assertDatabaseCount('promotions', 1);
});

it('rejects invalid promotion data at the PostgreSQL boundary', function (array $changes, string $constraint) {
    $attributes = Promotion::factory()->make()->getAttributes();

    expect(fn () => DB::transaction(fn () => DB::table('promotions')->insert(array_replace($attributes, $changes))))
        ->toThrow(QueryException::class, $constraint);
    $this->assertDatabaseCount('promotions', 0);
})->with([
    'unsupported type' => [['type' => 'other'], 'promotions_type_allowed'],
    'zero percentage' => [['value' => 0], 'promotions_value_valid'],
    'negative percentage' => [['value' => -1], 'promotions_value_valid'],
    'over 100 percent' => [['value' => 10001], 'promotions_value_valid'],
    'negative fixed amount' => [['type' => 'fixed', 'value' => -1], 'promotions_value_valid'],
    'zero fixed amount' => [['type' => 'fixed', 'value' => 0], 'promotions_value_valid'],
    'negative minimum' => [['minimum_cart_amount_minor' => -1], 'promotions_minimum_non_negative'],
    'null minimum' => [['minimum_cart_amount_minor' => null], 'not-null constraint'],
    'negative cap' => [['maximum_discount_minor' => -1], 'promotions_maximum_positive'],
    'zero cap' => [['maximum_discount_minor' => 0], 'promotions_maximum_positive'],
    'zero global limit' => [['global_usage_limit' => 0], 'promotions_global_limit_positive'],
    'negative global limit' => [['global_usage_limit' => -1], 'promotions_global_limit_positive'],
    'zero customer limit' => [['per_customer_usage_limit' => 0], 'promotions_customer_limit_positive'],
    'negative customer limit' => [['per_customer_usage_limit' => -1], 'promotions_customer_limit_positive'],
    'reversed dates' => [['starts_at' => '2026-10-07T00:00:00Z', 'expires_at' => '2026-10-06T00:00:00Z'], 'promotions_dates_ordered'],
    'empty window' => [['starts_at' => '2026-10-06T00:00:00Z', 'expires_at' => '2026-10-06T00:00:00Z'], 'promotions_dates_ordered'],
    'noncanonical import' => [['code' => ' summer20 '], 'promotions_code_normalized'],
    'blank import' => [['code' => ''], 'promotions_code_normalized'],
]);

it('accepts percentage and fixed boundaries with nullable unlimited limits', function (PromotionType $type, int $value) {
    $promotion = Promotion::factory()->create(['type' => $type, 'value' => $value]);

    expect($promotion->fresh()->type)->toBe($type);
    expect($promotion->fresh()->value)->toBe($value);
    expect($promotion->fresh()->global_usage_limit)->toBeNull();
    expect($promotion->fresh()->per_customer_usage_limit)->toBeNull();
})->with([[PromotionType::Percentage, 1], [PromotionType::Percentage, 10000], [PromotionType::Fixed, 1], [PromotionType::Fixed, PHP_INT_MAX]]);

it('defaults to active with zero minimum and allows positive limits and caps', function () {
    $attributes = Promotion::factory()->limited()->make(['maximum_discount_minor' => 1])->getAttributes();
    unset($attributes['is_active'], $attributes['minimum_cart_amount_minor']);
    $id = DB::table('promotions')->insertGetId($attributes);
    $promotion = Promotion::query()->findOrFail($id);

    expect($promotion->is_active)->toBeTrue();
    expect($promotion->minimum_cart_amount_minor)->toBe(0);
    expect($promotion->global_usage_limit)->toBe(5);
    expect($promotion->per_customer_usage_limit)->toBe(1);
});

it('enforces a valid selected promotion FK without mass assigning a client selection', function () {
    $cart = Cart::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('carts')->where('id', $cart->id)->update(['promotion_id' => PHP_INT_MAX])))
        ->toThrow(QueryException::class, 'carts_promotion_id_foreign');
    expect($cart->fresh()->promotion_id)->toBeNull();
    expect($cart->isFillable('promotion_id'))->toBeFalse();
});

it('detaches deleted unredeemed promotions while preserving cart items', function () {
    $promotion = Promotion::factory()->create();
    $cart = Cart::factory()->hasItems(1)->create();
    $cart->promotion()->associate($promotion)->save();

    expect($cart->fresh()->promotion->id)->toBe($promotion->id);
    expect($promotion->carts->sole()->id)->toBe($cart->id);
    $promotion->delete();

    expect($cart->fresh()->promotion_id)->toBeNull();
    $this->assertDatabaseCount('cart_items', 1);
});
