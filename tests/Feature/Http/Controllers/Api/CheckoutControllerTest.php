<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;
use App\Services\Cart\CartService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('returns 401 without valid bearer authentication', function (string $authentication) {
    $user = User::factory()->create();
    $token = $user->createToken('checkout');
    $token->accessToken->delete();

    if ($authentication !== 'missing') {
        $this->withToken($authentication === 'revoked' ? $token->plainTextToken : 'invalid');
    }

    $this->postJson('/api/checkout')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    $this->assertDatabaseCount('orders', 0);
})->with(['missing', 'invalid', 'revoked']);

it('creates an owner order with exact current prices snapshots quantities and inventory', function (bool $multipleProducts) {
    $product = Product::factory()->create(['name' => 'Purchase Name', 'sku' => 'PURCHASE-SKU', 'price_minor' => 1001, 'stock_quantity' => 10]);
    $item = CartItem::factory()->for($product)->create(['quantity' => 2]);
    $otherItem = CartItem::factory()->for($product)->create(['quantity' => 1]);

    if ($multipleProducts) {
        $secondProduct = Product::factory()->create(['price_minor' => 500, 'stock_quantity' => 8]);
        CartItem::factory()->for($item->cart)->for($secondProduct)->create(['quantity' => 3]);
    }

    $response = $this->withToken($item->cart->user->createToken('checkout')->plainTextToken)->postJson('/api/checkout', [
        'user_id' => $otherItem->cart->user_id, 'cart_id' => $otherItem->cart_id, 'price_minor' => 1,
        'subtotal' => 1, 'discount' => 999999, 'total' => 0, 'stock_quantity' => 99,
        'product_name' => 'Forged', 'sku' => 'FORGED', 'code' => 'FORGED', 'idempotency_key' => 'ignored-body-key',
    ])->assertCreated()->assertJsonPath('data.user_id', $item->cart->user_id)
        ->assertJsonPath('data.status', 'placed')->assertJsonCount($multipleProducts ? 2 : 1, 'data.items')
        ->assertJsonPath('data.subtotal.amount_minor', $multipleProducts ? 3502 : 2002)
        ->assertJsonPath('data.discount.amount_minor', 0)->assertJsonPath('data.total.amount_minor', $multipleProducts ? 3502 : 2002)
        ->assertJsonPath('data.promotion', null)->assertJsonPath('data.items.0.product_name', 'Purchase Name')
        ->assertJsonPath('data.items.0.product_sku', 'PURCHASE-SKU')->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.items.0.unit_price_minor', 1001)->assertJsonPath('data.items.0.line_subtotal_minor', 2002)
        ->assertJsonMissingPath('data.idempotency_key');

    expect($response->headers->get('X-Request-ID'))->not->toBeNull();
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    expect($product->fresh()->stock_quantity)->toBe(8);
    expect($item->cart->fresh()->items)->toBeEmpty();
    expect($otherItem->fresh()->quantity)->toBe(1);
    expect(Order::query()->sole()->idempotency_key)->toBeNull();
    $this->assertDatabaseCount('promotion_redemptions', 0);

    if ($multipleProducts) {
        expect($secondProduct->fresh()->stock_quantity)->toBe(5);
    }
})->with([false, true]);

it('uses prices changed after cart addition and preserves historical product promotion and currency snapshots on replay', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['name' => 'Original', 'sku' => 'ORIGINAL', 'price_minor' => 500, 'stock_quantity' => 8]);
    app(CartService::class)->addItem($user, $product->id, 2);
    $product->update(['price_minor' => 1001]);
    $promotion = Promotion::factory()->create(['code' => 'SAVE20']);
    $user->cart->promotion()->associate($promotion)->save();
    $token = $user->createToken('checkout')->plainTextToken;
    $first = $this->withToken($token)->postJson('/api/checkout', [], ['Idempotency-Key' => 'snapshots'])
        ->assertCreated()->assertJsonPath('data.subtotal.amount_minor', 2002)->assertJsonPath('data.discount.amount_minor', 400)
        ->assertJsonPath('data.total.amount_minor', 1602);
    $product->update(['price_minor' => 9999, 'name' => 'Changed', 'sku' => 'CHANGED']);
    $promotion->update(['code' => 'CHANGED', 'value' => 9000, 'is_active' => false]);
    config(['catalogue.currency' => 'EUR']);
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->postJson('/api/checkout', [], ['Idempotency-Key' => 'snapshots'])
        ->assertOk()->assertExactJson($first->json());
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('promotion_redemptions', 1);
    expect($product->fresh()->stock_quantity)->toBe(6);
});

it('calculates shared half-up fixed capped clamped and maximum integer discounts at checkout', function (int $price, string $type, int $value, ?int $cap, int $discount) {
    $item = CartItem::factory()->for(Product::factory()->create(['price_minor' => $price, 'stock_quantity' => 2]))->create();
    $promotion = Promotion::factory()->create(['type' => $type, 'value' => $value, 'maximum_discount_minor' => $cap]);
    $item->cart->promotion()->associate($promotion)->save();

    $response = $this->withToken($item->cart->user->createToken('checkout')->plainTextToken)->postJson('/api/checkout')
        ->assertCreated()->assertJsonPath('data.subtotal.amount_minor', $price)
        ->assertJsonPath('data.discount.amount_minor', $discount)->assertJsonPath('data.total.amount_minor', $price - $discount);

    $order = Order::query()->sole();
    $redemption = PromotionRedemption::query()->sole();
    expect($redemption->order_id)->toBe($order->id);
    expect($redemption->user_id)->toBe($item->cart->user_id);
    expect($redemption->promotion_id)->toBe($promotion->id);
    expect($redemption->discount_minor)->toBe($discount);
    expect($redemption->redemption_key)->toMatch('/^[0-9a-f-]{36}$/');
    expect($item->cart->fresh()->promotion_id)->toBeNull();
    expect($item->cart->fresh()->items)->toBeEmpty();
    expect($response->json('data.promotion.code'))->toBe($promotion->code);
})->with([
    'half-up' => [1005, 'percentage', 1000, null, 101],
    'fixed' => [1000, 'fixed', 333, null, 333],
    'percentage cap' => [1000, 'percentage', 9000, 250, 250],
    'fixed cap' => [1000, 'fixed', 900, 250, 250],
    'subtotal clamp' => [1000, 'fixed', 2000, null, 1000],
    'maximum bigint' => [PHP_INT_MAX, 'percentage', 10000, null, PHP_INT_MAX],
    'zero price' => [0, 'fixed', 100, null, 0],
]);

it('returns 409 for an absent or empty cart without creating an order', function (bool $persisted) {
    $user = User::factory()->create();
    if ($persisted) {
        $cart = Cart::factory()->for($user)->create();
        $promotion = Promotion::factory()->create();
        $cart->promotion()->associate($promotion)->save();
    }

    $this->withToken($user->createToken('checkout')->plainTextToken)->postJson('/api/checkout')
        ->assertConflict()->assertJsonPath('error.code', 'CART_EMPTY');
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
    $this->assertDatabaseCount('promotion_redemptions', 0);
    $this->assertDatabaseCount('carts', $persisted ? 1 : 0);
    if ($persisted) {
        expect($cart->fresh()->promotion_id)->toBe($promotion->id);
    }
})->with([false, true]);

it('returns 409 for changed stock or inactive products and preserves the complete cart', function (array $changes, string $code) {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 5]))->create(['quantity' => 4]);
    $promotion = Promotion::factory()->create();
    $item->cart->promotion()->associate($promotion)->save();
    $item->product->update($changes);
    $stock = $item->product->fresh()->stock_quantity;

    $this->withToken($item->cart->user->createToken('checkout')->plainTextToken)->postJson('/api/checkout')
        ->assertConflict()->assertJsonPath('error.code', $code);

    expect($item->fresh()->quantity)->toBe(4);
    expect($item->cart->fresh()->promotion_id)->toBe($promotion->id);
    expect($item->product->fresh()->stock_quantity)->toBe($stock);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
    $this->assertDatabaseCount('promotion_redemptions', 0);
})->with([
    [['stock_quantity' => 3], 'INSUFFICIENT_STOCK'],
    [['stock_quantity' => 0], 'INSUFFICIENT_STOCK'],
    [['status' => 'inactive'], 'PRODUCT_INACTIVE'],
]);

it('rechecks invalid date status and minimum promotions and returns 422 without consuming usage', function (string $change, string $code) {
    $this->travelTo(CarbonImmutable::parse('2026-10-06T12:00:00Z'));
    $item = CartItem::factory()->for(Product::factory()->create(['price_minor' => 1000, 'stock_quantity' => 5]))->create(['quantity' => 2]);
    $promotion = Promotion::factory()->create(['starts_at' => now()->subDay(), 'expires_at' => now()->addDay(), 'minimum_cart_amount_minor' => 2000]);
    $item->cart->promotion()->associate($promotion)->save();
    match ($change) {
        'expired' => $this->travelTo(now()->addDay()),
        'future' => $promotion->update(['starts_at' => now()->addHour()]),
        'inactive' => $promotion->update(['is_active' => false]),
        'price' => $item->product->update(['price_minor' => 999]),
        'quantity' => $item->update(['quantity' => 1]),
    };

    $this->withToken($item->cart->user->createToken('checkout')->plainTextToken)->postJson('/api/checkout')
        ->assertUnprocessable()->assertJsonPath('error.code', $code);

    $this->assertModelExists($item);
    expect($item->cart->fresh()->promotion_id)->toBe($promotion->id);
    expect($item->product->fresh()->stock_quantity)->toBe(5);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
    $this->assertDatabaseCount('promotion_redemptions', 0);
})->with([
    ['expired', 'PROMOTION_EXPIRED'], ['future', 'PROMOTION_NOT_STARTED'], ['inactive', 'PROMOTION_INACTIVE'],
    ['price', 'PROMOTION_MINIMUM_NOT_MET'], ['quantity', 'PROMOTION_MINIMUM_NOT_MET'],
]);

it('returns 409 for exhausted global or customer usage including legacy ledger entries', function (bool $customerLimit, string $code) {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 5]))->create();
    $promotion = Promotion::factory()->create($customerLimit ? ['per_customer_usage_limit' => 1] : ['global_usage_limit' => 1]);
    $item->cart->promotion()->associate($promotion)->save();
    PromotionRedemption::factory()->for($promotion)->for($item->cart->user)->create();

    $this->withToken($item->cart->user->createToken('checkout')->plainTextToken)->postJson('/api/checkout')
        ->assertConflict()->assertJsonPath('error.code', $code);

    $this->assertDatabaseCount('promotion_redemptions', 1);
    $this->assertDatabaseCount('orders', 0);
    $this->assertModelExists($item);
    expect($item->cart->fresh()->promotion_id)->toBe($promotion->id);
    expect($item->product->fresh()->stock_quantity)->toBe(5);
})->with([[false, 'PROMOTION_GLOBAL_USAGE_LIMIT_REACHED'], [true, 'PROMOTION_CUSTOMER_USAGE_LIMIT_REACHED']]);

it('returns 409 on multiplication or accumulation overflow before persisting changes', function (bool $accumulation) {
    $item = CartItem::factory()->for(Product::factory()->create(['price_minor' => PHP_INT_MAX, 'stock_quantity' => 2]))->create(['quantity' => $accumulation ? 1 : 2]);
    if ($accumulation) {
        CartItem::factory()->for($item->cart)->for(Product::factory()->create(['price_minor' => 1]))->create();
    }

    $this->withToken($item->cart->user->createToken('checkout')->plainTextToken)->postJson('/api/checkout')
        ->assertConflict()->assertJsonPath('error.code', 'CART_TOTAL_TOO_LARGE');

    $this->assertModelExists($item);
    $this->assertDatabaseCount('cart_items', $accumulation ? 2 : 1);
    $this->assertDatabaseCount('orders', 0);
    expect($item->product->fresh()->stock_quantity)->toBe(2);
})->with([false, true]);

it('replays a successful key before empty-cart or current eligibility checks without duplicate writes', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 5]))->create(['quantity' => 2]);
    $promotion = Promotion::factory()->limited(1, 1)->create();
    $item->cart->promotion()->associate($promotion)->save();
    $token = $item->cart->user->createToken('checkout')->plainTextToken;
    $first = $this->withToken($token)->postJson('/api/checkout', [], ['Idempotency-Key' => 'retry'])
        ->assertCreated();
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->postJson('/api/checkout', [], ['Idempotency-Key' => 'retry'])
        ->assertOk()->assertExactJson($first->json());

    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 1);
    $this->assertDatabaseCount('promotion_redemptions', 1);
    expect($item->product->fresh()->stock_quantity)->toBe(3);
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->postJson('/api/checkout', [], ['Idempotency-Key' => 'different'])
        ->assertConflict()->assertJsonPath('error.code', 'CART_EMPTY');
    $this->assertDatabaseCount('orders', 1);
});

it('keeps a new cart untouched when an old key is reused and buys it only with a new key', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 5]))->create();
    $token = $item->cart->user->createToken('checkout')->plainTextToken;
    $first = $this->withToken($token)->postJson('/api/checkout', [], ['Idempotency-Key' => 'old'])->assertCreated();
    $newItem = CartItem::factory()->for($item->cart)->for($item->product)->create();
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->postJson('/api/checkout', ['total' => 0, 'user_id' => 999], ['Idempotency-Key' => 'old'])
        ->assertOk()->assertExactJson($first->json());

    $this->assertModelExists($newItem);
    expect($item->product->fresh()->stock_quantity)->toBe(4);
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->postJson('/api/checkout', [], ['Idempotency-Key' => 'new'])->assertCreated();
    $this->assertDatabaseCount('orders', 2);
    expect($item->product->fresh()->stock_quantity)->toBe(3);
});

it('scopes the same key to each customer', function () {
    $product = Product::factory()->create(['stock_quantity' => 5]);
    $first = CartItem::factory()->for($product)->create();
    $second = CartItem::factory()->for($product)->create();
    $firstResponse = $this->withToken($first->cart->user->createToken('checkout')->plainTextToken)
        ->postJson('/api/checkout', [], ['Idempotency-Key' => 'shared'])->assertCreated();
    $this->app['auth']->forgetGuards();

    $secondResponse = $this->withToken($second->cart->user->createToken('checkout')->plainTextToken)
        ->postJson('/api/checkout', [], ['Idempotency-Key' => 'shared'])->assertCreated()
        ->assertJsonPath('data.user_id', $second->cart->user_id);

    expect($secondResponse->json('data.id'))->not->toBe($firstResponse->json('data.id'));
    $this->assertDatabaseCount('orders', 2);
    expect($product->fresh()->stock_quantity)->toBe(3);
});

it('returns 422 for invalid idempotency headers before checkout and preserves the cart', function (string $key) {
    $item = CartItem::factory()->create();

    $this->withToken($item->cart->user->createToken('checkout')->plainTextToken)
        ->postJson('/api/checkout', [], ['Idempotency-Key' => $key])->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['fields' => ['idempotency_key']]]]);

    $this->assertModelExists($item);
    $this->assertDatabaseCount('orders', 0);
})->with(['', ' ', ' leading', 'trailing ', 'with space', '-start', 'bad/key', 'é', str_repeat('a', 129), "bad\nkey", 'a,b']);

it('accepts a maximum-length case-sensitive ASCII key', function () {
    $item = CartItem::factory()->create();
    $key = 'A'.str_repeat('x', 123).'._:-';

    $this->withToken($item->cart->user->createToken('checkout')->plainTextToken)
        ->postJson('/api/checkout', [], ['Idempotency-Key' => $key])->assertCreated();

    expect(Order::query()->sole()->idempotency_key)->toBe($key);
});
