<?php

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06T12:00:00Z'));
});

it('returns 401 for missing invalid or revoked authentication on both promotion endpoints', function (string $method, string $authentication) {
    $user = User::factory()->create();
    $token = $user->createToken('promotion-test');
    $token->accessToken->delete();

    if ($authentication !== 'missing') {
        $this->withToken($authentication === 'revoked' ? $token->plainTextToken : 'invalid');
    }

    $this->json($method, '/api/cart/promotion', ['code' => 'SAVE15'])
        ->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    $this->assertDatabaseCount('carts', 0);
})->with(['POST', 'DELETE'])->with(['missing', 'invalid', 'revoked']);

it('applies a normalized capped percentage using current server prices and ignores forged fields', function () {
    $otherCart = Cart::factory()->create();
    $item = CartItem::factory()->for(Product::factory()->create(['price_minor' => 7500, 'stock_quantity' => 10]))->create(['quantity' => 2]);
    $promotion = Promotion::factory()->create(['code' => 'SUMMER20', 'minimum_cart_amount_minor' => 10000, 'maximum_discount_minor' => 2500]);

    $response = $this->withToken($item->cart->user->createToken('promotion-test')->plainTextToken)
        ->postJson('/api/cart/promotion', [
            'code' => ' summer20 ', 'user_id' => $otherCart->user_id, 'cart_id' => $otherCart->id,
            'promotion_id' => PHP_INT_MAX, 'estimated_discount' => 15000, 'subtotal' => 1,
        ])->assertOk()
        ->assertJsonPath('data.subtotal', ['amount_minor' => 15000, 'currency' => 'USD'])
        ->assertJsonPath('data.estimated_discount', ['amount_minor' => 2500, 'currency' => 'USD'])
        ->assertJsonPath('data.estimated_total', ['amount_minor' => 12500, 'currency' => 'USD'])
        ->assertJsonPath('data.promotion.code', 'SUMMER20')
        ->assertJsonPath('data.promotion.percentage_basis_points', 2000)
        ->assertJsonPath('data.promotion.fixed_amount', null)
        ->assertJsonPath('data.promotion_eligibility', ['is_eligible' => true, 'reason' => null, 'message' => null])
        ->assertJsonMissingPath('data.promotion.redemptions')
        ->assertJsonMissingPath('data.promotion.global_usage_limit')
        ->assertJsonMissingPath('data.user_id')
        ->assertHeader('X-Request-ID');
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    expect(array_keys($response->json('data.promotion')))->toBe(['id', 'code', 'type', 'percentage_basis_points', 'fixed_amount', 'minimum_cart_amount', 'maximum_discount', 'starts_at', 'expires_at']);

    expect($item->cart->fresh()->promotion_id)->toBe($promotion->id);
    expect($otherCart->fresh()->promotion_id)->toBeNull();
    expect($item->fresh()->quantity)->toBe(2);
    expect($item->product->fresh()->stock_quantity)->toBe(10);
    $this->assertDatabaseCount('promotion_redemptions', 0);
});

it('replaces a selected code and repeatedly applies the same limited code without consuming usage', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['price_minor' => 8000]))->create();
    $original = Promotion::factory()->create();
    $replacement = Promotion::factory()->fixed()->limited(1, 1)->create();
    $token = $item->cart->user->createToken('promotion-test')->plainTextToken;

    $this->withToken($token)->postJson('/api/cart/promotion', ['code' => $original->code])->assertOk();

    foreach (range(1, 2) as $attempt) {
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/cart/promotion', ['code' => $replacement->code])
            ->assertOk()->assertJsonPath('data.promotion.id', $replacement->id)
            ->assertJsonPath('data.promotion.fixed_amount', ['amount_minor' => 1500, 'currency' => 'USD'])
            ->assertJsonPath('data.estimated_discount.amount_minor', 1500)
            ->assertJsonPath('data.estimated_total.amount_minor', 6500);
    }

    expect($item->cart->fresh()->promotion_id)->toBe($replacement->id);
    $this->assertDatabaseCount('carts', 1);
    $this->assertDatabaseCount('cart_items', 1);
    $this->assertDatabaseCount('promotion_redemptions', 0);
});

it('returns safe promotion errors and preserves the previous selection on a failed application', function (array $attributes, string $code, int $status, string $error) {
    $item = CartItem::factory()->for(Product::factory()->create(['price_minor' => 10000]))->create();
    $original = Promotion::factory()->create();
    $item->cart->promotion()->associate($original)->save();
    Promotion::factory()->create(array_replace(['code' => 'REJECTED'], $attributes));

    $response = $this->withToken($item->cart->user->createToken('promotion-test')->plainTextToken)
        ->postJson('/api/cart/promotion', ['code' => $code])
        ->assertStatus($status)->assertJsonPath('error.code', $error)
        ->assertJsonMissingPath('exception')->assertJsonMissingPath('error.details');
    expect($response->json('error.request_id'))->toBe($response->headers->get('X-Request-ID'));
    expect($response->getContent())->not->toContain('SQLSTATE', 'trace');

    expect($item->cart->fresh()->promotion_id)->toBe($original->id);
    expect($item->fresh()->quantity)->toBe(1);
    $this->assertDatabaseCount('promotion_redemptions', 0);
})->with([
    'unknown 404' => [[], 'UNKNOWN', 404, 'PROMOTION_NOT_FOUND'],
    'inactive 422' => [['is_active' => false], 'REJECTED', 422, 'PROMOTION_INACTIVE'],
    'expired 422' => [['expires_at' => '2026-10-06T12:00:00Z'], 'REJECTED', 422, 'PROMOTION_EXPIRED'],
    'future 422' => [['starts_at' => '2026-10-07T12:00:00Z'], 'REJECTED', 422, 'PROMOTION_NOT_STARTED'],
    'below minimum 422' => [['minimum_cart_amount_minor' => 10001], 'REJECTED', 422, 'PROMOTION_MINIMUM_NOT_MET'],
]);

it('returns 422 for invalid code input before changing cart state', function (array $payload, string $message) {
    $user = User::factory()->create();

    $this->withToken($user->createToken('promotion-test')->plainTextToken)->postJson('/api/cart/promotion', $payload)
        ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonPath('error.details.fields.code.0', $message);
    $this->assertDatabaseCount('carts', 0);
})->with([
    'missing' => [[], 'The code field is required.'],
    'blank' => [['code' => '   '], 'The code field is required.'],
    'null' => [['code' => null], 'The code field is required.'],
    'integer' => [['code' => 123], 'The code field must be a string.'],
    'array' => [['code' => ['SAVE15']], 'The code field must be a string.'],
    'boolean' => [['code' => true], 'The code field must be a string.'],
    'too long' => [['code' => str_repeat('A', 65)], 'The code field must not be greater than 64 characters.'],
    'embedded null byte' => [['code' => "SA\0VE"], 'The code field format is invalid.'],
    'SQL fragment' => [['code' => "' OR 1=1 --"], 'The code field format is invalid.'],
]);

it('returns 409 for absent and persisted empty carts without creating or attaching anything', function (bool $persisted) {
    $user = User::factory()->create();
    $promotion = Promotion::factory()->create();

    if ($persisted) {
        Cart::factory()->for($user)->create();
    }

    $this->withToken($user->createToken('promotion-test')->plainTextToken)->postJson('/api/cart/promotion', ['code' => $promotion->code])
        ->assertConflict()->assertJsonPath('error.code', 'EMPTY_CART');
    $this->assertDatabaseMissing('carts', ['promotion_id' => $promotion->id]);
    $this->assertDatabaseCount('carts', $persisted ? 1 : 0);
})->with([false, true]);

it('returns 409 if any line is not purchasable and leaves the promotion unchanged', function (array $productChanges) {
    $item = CartItem::factory()->create(['quantity' => 2]);
    $promotion = Promotion::factory()->create();
    $item->product->update($productChanges);

    $this->withToken($item->cart->user->createToken('promotion-test')->plainTextToken)->postJson('/api/cart/promotion', ['code' => $promotion->code])
        ->assertConflict()->assertJsonPath('error.code', 'INVALID_CART_STATE');
    expect($item->cart->fresh()->promotion_id)->toBeNull();
    expect($item->fresh()->quantity)->toBe(2);
})->with([
    [['status' => ProductStatus::Inactive]], [['stock_quantity' => 0]], [['stock_quantity' => 1]],
]);

it('removes repeatedly without affecting another customer stock items or committed redemptions', function () {
    $promotion = Promotion::factory()->create();
    $item = CartItem::factory()->create();
    $otherCart = Cart::factory()->create();
    $item->cart->promotion()->associate($promotion)->save();
    $otherCart->promotion()->associate($promotion)->save();
    PromotionRedemption::factory()->for($promotion)->for($item->cart->user)->create();
    $stock = $item->product->stock_quantity;
    $token = $item->cart->user->createToken('promotion-test')->plainTextToken;

    foreach (range(1, 2) as $attempt) {
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->deleteJson('/api/cart/promotion', ['cart_id' => $otherCart->id, 'user_id' => $otherCart->user_id])->assertNoContent();
    }

    expect($item->cart->fresh()->promotion_id)->toBeNull();
    expect($otherCart->fresh()->promotion_id)->toBe($promotion->id);
    expect($item->fresh()->quantity)->toBe(1);
    expect($item->product->fresh()->stock_quantity)->toBe($stock);
    $this->assertDatabaseCount('promotion_redemptions', 1);
});

it('removes from an absent cart idempotently without inserting one', function () {
    $user = User::factory()->create();

    $this->withToken($user->createToken('promotion-test')->plainTextToken)->deleteJson('/api/cart/promotion')->assertNoContent();
    $this->assertDatabaseCount('carts', 0);
});

it('rechecks selected promotions on GET after status date or usage changes', function (string $change, string $reason) {
    $item = CartItem::factory()->for(Product::factory()->create(['price_minor' => 10000]))->create();
    $promotion = Promotion::factory()->limited(2, 1)->create(['expires_at' => '2026-10-07T12:00:00Z']);
    $token = $item->cart->user->createToken('promotion-test')->plainTextToken;
    $this->withToken($token)->postJson('/api/cart/promotion', ['code' => $promotion->code])->assertOk();

    match ($change) {
        'inactive' => $promotion->update(['is_active' => false]),
        'expired' => $this->travelTo(CarbonImmutable::parse('2026-10-07T12:00:00Z')),
        'global' => PromotionRedemption::factory()->count(2)->for($promotion)->create(),
        'customer' => PromotionRedemption::factory()->for($promotion)->for($item->cart->user)->create(),
    };
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/cart')->assertOk()
        ->assertJsonPath('data.promotion.id', $promotion->id)
        ->assertJsonPath('data.promotion_eligibility.is_eligible', false)
        ->assertJsonPath('data.promotion_eligibility.reason', $reason)
        ->assertJsonPath('data.estimated_discount.amount_minor', 0)
        ->assertJsonPath('data.estimated_total.amount_minor', 10000);
    expect($item->cart->fresh()->promotion_id)->toBe($promotion->id);
})->with([
    ['inactive', 'PROMOTION_INACTIVE'], ['expired', 'PROMOTION_EXPIRED'],
    ['global', 'PROMOTION_GLOBAL_USAGE_LIMIT_REACHED'], ['customer', 'PROMOTION_CUSTOMER_USAGE_LIMIT_REACHED'],
]);

it('returns 409 on application when committed global or customer usage is exhausted', function (bool $customerLimit, string $reason) {
    $item = CartItem::factory()->create();
    $promotion = Promotion::factory()->create($customerLimit ? ['per_customer_usage_limit' => 1] : ['global_usage_limit' => 1]);
    PromotionRedemption::factory()->for($promotion)->for($item->cart->user)->create();

    $this->withToken($item->cart->user->createToken('promotion-test')->plainTextToken)->postJson('/api/cart/promotion', ['code' => $promotion->code])
        ->assertConflict()->assertJsonPath('error.code', $reason);
    expect($item->cart->fresh()->promotion_id)->toBeNull();
    $this->assertDatabaseCount('promotion_redemptions', 1);
})->with([[false, 'PROMOTION_GLOBAL_USAGE_LIMIT_REACHED'], [true, 'PROMOTION_CUSTOMER_USAGE_LIMIT_REACHED']]);

it('recalculates minimum eligibility on quantity mutation and restores discount when eligible again', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['price_minor' => 5000, 'stock_quantity' => 10]))->create(['quantity' => 2]);
    $promotion = Promotion::factory()->create(['minimum_cart_amount_minor' => 10000]);
    $token = $item->cart->user->createToken('promotion-test')->plainTextToken;
    $this->withToken($token)->postJson('/api/cart/promotion', ['code' => $promotion->code])->assertOk();
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->patchJson('/api/cart/items/'.$item->id, ['quantity' => 1])->assertOk()
        ->assertJsonPath('data.subtotal.amount_minor', 5000)
        ->assertJsonPath('data.promotion_eligibility.reason', 'PROMOTION_MINIMUM_NOT_MET')
        ->assertJsonPath('data.estimated_discount.amount_minor', 0)
        ->assertJsonPath('data.estimated_total.amount_minor', 5000);
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->postJson('/api/cart/items', ['product_id' => $item->product_id, 'quantity' => 1])->assertCreated()
        ->assertJsonPath('data.promotion_eligibility.is_eligible', true)
        ->assertJsonPath('data.estimated_discount.amount_minor', 2000)
        ->assertJsonPath('data.estimated_total.amount_minor', 8000);
    expect($item->cart->fresh()->promotion_id)->toBe($promotion->id);
    $this->assertDatabaseCount('promotion_redemptions', 0);
});

it('rechecks changed product prices and availability against the selected code', function (array $changes, string $reason, int $subtotal) {
    $item = CartItem::factory()->for(Product::factory()->create(['price_minor' => 5000, 'stock_quantity' => 10]))->create(['quantity' => 2]);
    $promotion = Promotion::factory()->create(['minimum_cart_amount_minor' => 10000]);
    $item->cart->promotion()->associate($promotion)->save();
    $item->product->update($changes);

    $this->withToken($item->cart->user->createToken('promotion-test')->plainTextToken)->getJson('/api/cart')->assertOk()
        ->assertJsonPath('data.subtotal.amount_minor', $subtotal)
        ->assertJsonPath('data.promotion_eligibility.reason', $reason)
        ->assertJsonPath('data.estimated_discount.amount_minor', 0)
        ->assertJsonPath('data.estimated_total.amount_minor', $subtotal);
    expect($item->cart->fresh()->promotion_id)->toBe($promotion->id);
})->with([
    [['price_minor' => 4999], 'PROMOTION_MINIMUM_NOT_MET', 9998],
    [['status' => ProductStatus::Inactive], 'INVALID_CART_STATE', 10000],
    [['stock_quantity' => 0], 'INVALID_CART_STATE', 10000],
    [['stock_quantity' => 1], 'INVALID_CART_STATE', 10000],
]);

it('preserves an invalid selected code after deleting the last line', function () {
    $item = CartItem::factory()->create();
    $promotion = Promotion::factory()->create();
    $item->cart->promotion()->associate($promotion)->save();
    $token = $item->cart->user->createToken('promotion-test')->plainTextToken;

    $this->withToken($token)->deleteJson('/api/cart/items/'.$item->id)->assertNoContent();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/cart')->assertOk()
        ->assertJsonPath('data.items', [])
        ->assertJsonPath('data.promotion.id', $promotion->id)
        ->assertJsonPath('data.promotion_eligibility.reason', 'EMPTY_CART')
        ->assertJsonPath('data.estimated_discount.amount_minor', 0)
        ->assertJsonPath('data.estimated_total.amount_minor', 0);
});

it('uses configured currency and current multi-line subtotals for fixed discounts', function () {
    config(['catalogue.currency' => 'EUR']);
    $item = CartItem::factory()->for(Product::factory()->create(['price_minor' => 1000, 'stock_quantity' => 2]))->create(['quantity' => 2]);
    CartItem::factory()->for($item->cart)->for(Product::factory()->create(['price_minor' => 500, 'stock_quantity' => 3]))->create(['quantity' => 3]);
    $promotion = Promotion::factory()->fixed(4000)->create();

    $this->withToken($item->cart->user->createToken('promotion-test')->plainTextToken)->postJson('/api/cart/promotion', ['code' => $promotion->code])
        ->assertOk()->assertJsonPath('data.subtotal', ['amount_minor' => 3500, 'currency' => 'EUR'])
        ->assertJsonPath('data.estimated_discount', ['amount_minor' => 3500, 'currency' => 'EUR'])
        ->assertJsonPath('data.estimated_total', ['amount_minor' => 0, 'currency' => 'EUR']);
});

it('returns exact large discounts and handles subtotal overflow without changing the selection', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['price_minor' => PHP_INT_MAX, 'stock_quantity' => 2]))->create();
    $promotion = Promotion::factory()->create(['value' => 10000]);
    $token = $item->cart->user->createToken('promotion-test')->plainTextToken;
    $this->withToken($token)->postJson('/api/cart/promotion', ['code' => $promotion->code])->assertOk()
        ->assertJsonPath('data.estimated_discount.amount_minor', PHP_INT_MAX)
        ->assertJsonPath('data.estimated_total.amount_minor', 0);
    $item->update(['quantity' => 2]);
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->postJson('/api/cart/promotion', ['code' => $promotion->code])
        ->assertConflict()->assertJsonPath('error.code', 'CART_TOTAL_TOO_LARGE');
    expect($item->cart->fresh()->promotion_id)->toBe($promotion->id);
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->deleteJson('/api/cart/promotion')->assertNoContent();
    expect($item->cart->fresh()->promotion_id)->toBeNull();
});

it('loads selected promotions with a bounded query count without lazy resource queries', function () {
    $promotion = Promotion::factory()->limited()->create();
    $cart = Cart::factory()->hasItems(4)->create();
    $cart->promotion()->associate($promotion)->save();
    $token = $cart->user->createToken('promotion-test')->plainTextToken;
    DB::enableQueryLog();

    $this->withToken($token)->getJson('/api/cart')->assertOk()->assertJsonPath('data.promotion_eligibility.is_eligible', true);

    $cartQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/from "(carts|cart_items|products|promotions|promotion_redemptions)"/', $query['query']) === 1);
    expect($cartQueries)->toHaveCount(5);
    DB::disableQueryLog();
});
