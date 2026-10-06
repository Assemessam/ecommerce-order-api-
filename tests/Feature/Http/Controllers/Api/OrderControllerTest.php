<?php

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Checkout\CheckoutService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

it('requires authentication with 401 on every order endpoint', function (string $method, string $path) {
    $this->json($method, $path)->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
})->with([['GET', '/api/orders'], ['GET', '/api/orders/1'], ['POST', '/api/orders/1/cancel']]);

it('lists only owned orders in creation date and descending ID order without loading items or private relations', function () {
    $user = User::factory()->create();
    $older = Order::factory()->for($user)->create(['created_at' => '2026-01-01 00:00:00+00']);
    $newer = Order::factory()->for($user)->create(['created_at' => '2026-01-02 00:00:00+00']);
    $tie = Order::factory()->for($user)->create(['created_at' => '2026-01-02 00:00:00+00']);
    $other = Order::factory()->create();
    $token = $user->createToken('orders')->plainTextToken;
    DB::enableQueryLog();

    $response = $this->withToken($token)->getJson('/api/orders?user_id='.$other->user_id.'&sort=total_minor&direction=asc')
        ->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.per_page', 15)
        ->assertJsonMissingPath('data.0.items')->assertJsonMissingPath('data.0.user')
        ->assertJsonMissingPath('data.0.idempotency_key')->assertJsonMissingPath('data.0.redemption');

    expect(array_column($response->json('data'), 'id'))->toBe([$tie->id, $newer->id, $older->id]);
    expect($response->json('links.first'))->not->toContain('user_id', 'sort', 'direction');
    $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    expect($queries)->not->toContain('"order_items"', '"products"', '"promotions"', '"promotion_redemptions"');
    DB::disableQueryLog();
});

it('paginates history with a default of fifteen and accepts at most one hundred', function () {
    $user = User::factory()->create();
    Order::factory()->count(17)->for($user)->create();
    $this->withToken($user->createToken('orders')->plainTextToken);

    $this->getJson('/api/orders')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('meta.total', 17);
    $this->getJson('/api/orders?page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.current_page', 2);
    $this->getJson('/api/orders?per_page=100')->assertOk()->assertJsonCount(17, 'data')->assertJsonPath('meta.per_page', 100);
    $this->getJson('/api/orders?page=3&per_page=5')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.current_page', 3);
});

it('returns an empty paginated history without creating orders', function () {
    $this->withToken(User::factory()->create()->createToken('orders')->plainTextToken)->getJson('/api/orders')
        ->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0)->assertJsonPath('meta.from', null);
    $this->assertDatabaseCount('orders', 0);
});

it('rejects invalid history pagination with 422 and field errors', function (string $query, string $field, string $message) {
    $this->withToken(User::factory()->create()->createToken('orders')->plainTextToken)->getJson('/api/orders?'.$query)
        ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonPath('error.details.fields.'.$field.'.0', $message);
})->with([
    ['page=0', 'page', 'The page field must be at least 1.'],
    ['page=2147483648', 'page', 'The page field must not be greater than 2147483647.'],
    ['page=abc', 'page', 'The page field must be an integer.'],
    ['page=', 'page', 'The page field is required.'],
    ['per_page=0', 'per_page', 'The per page field must be at least 1.'],
    ['per_page=101', 'per_page', 'The per page field must not be greater than 100.'],
    ['per_page=1.5', 'per_page', 'The per page field must be an integer.'],
    ['per_page=', 'per_page', 'The per page field is required.'],
]);

it('uses only query pagination ignoring forged GET body values', function () {
    $user = User::factory()->create();
    Order::factory()->count(3)->for($user)->create();
    $this->withToken($user->createToken('orders')->plainTextToken)
        ->json('GET', '/api/orders?per_page=2', ['page' => 2, 'per_page' => 101, 'user_id' => PHP_INT_MAX])
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.per_page', 2);
});

it('returns stored item totals names SKUs currency and promotion snapshots after live data changes', function () {
    $first = Product::factory()->create(['name' => 'Original first', 'sku' => 'FIRST', 'price_minor' => 1200, 'stock_quantity' => 10]);
    $second = Product::factory()->create(['name' => 'Original second', 'sku' => 'SECOND', 'price_minor' => 700, 'stock_quantity' => 10]);
    $item = CartItem::factory()->for($first)->create(['quantity' => 2]);
    CartItem::factory()->for($item->cart)->for($second)->create(['quantity' => 3]);
    $promotion = Promotion::factory()->fixed(500)->create(['code' => 'ORIGINAL']);
    $item->cart->promotion()->associate($promotion)->save();
    $order = app(CheckoutService::class)->checkout($item->cart->user, 'private-key')->order;
    $first->update(['name' => 'New first', 'sku' => 'NEW-FIRST', 'price_minor' => 9999]);
    $second->update(['name' => 'New second', 'sku' => 'NEW-SECOND', 'price_minor' => 1]);
    $promotion->update(['code' => 'CHANGED', 'value' => 999]);
    config(['catalogue.currency' => 'EUR']);
    $token = $item->cart->user->createToken('orders')->plainTextToken;

    $this->withToken($token)->getJson('/api/orders/'.$order->id)->assertOk()->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.status', 'placed')
        ->assertJsonPath('data.items.0.product_name', 'Original first')->assertJsonPath('data.items.0.product_sku', 'FIRST')
        ->assertJsonPath('data.items.0.quantity', 2)->assertJsonPath('data.items.0.unit_price_minor', 1200)
        ->assertJsonPath('data.items.0.line_subtotal_minor', 2400)
        ->assertJsonPath('data.items.1.product_name', 'Original second')->assertJsonPath('data.items.1.product_sku', 'SECOND')
        ->assertJsonPath('data.items.1.line_subtotal_minor', 2100)->assertJsonPath('data.subtotal.amount_minor', 4500)
        ->assertJsonPath('data.discount.amount_minor', 500)->assertJsonPath('data.total.amount_minor', 4000)
        ->assertJsonPath('data.promotion.code', 'ORIGINAL')->assertJsonPath('data.promotion.value', 500)
        ->assertJsonPath('data.cancelled_at', null)->assertJsonPath('data.inventory_restored_at', null)
        ->assertJsonMissingPath('data.user')->assertJsonMissingPath('data.idempotency_key')->assertJsonMissingPath('data.items.0.product');
    $this->getJson('/api/orders')->assertOk()->assertJsonPath('data.0.total.amount_minor', 4000)
        ->assertJsonPath('data.0.currency', 'USD')->assertJsonMissingPath('data.0.items');
});

it('returns the same 404 for missing foreign malformed and overflowing order IDs', function (string $method, string $id) {
    $foreign = Order::factory()->create();
    $target = $id === 'foreign' ? (string) $foreign->id : $id;
    $path = '/api/orders/'.$target.($method === 'POST' ? '/cancel' : '');
    $this->withToken(User::factory()->create()->createToken('orders')->plainTextToken)->json($method, $path)
        ->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND')
        ->assertJsonPath('error.message', 'The requested resource was not found.');
    expect($foreign->fresh()->status)->toBe(OrderStatus::Placed);
})->with(['GET', 'POST'])->with(['foreign', '99999', 'abc', '0', '9223372036854775808']);

it('cancels multiple purchased products exactly once including inactive inventory while preserving all history and cart state', function () {
    $this->freezeTime();
    $first = Product::factory()->create(['stock_quantity' => 10, 'price_minor' => 1000]);
    $second = Product::factory()->create(['stock_quantity' => 8, 'price_minor' => 500]);
    $item = CartItem::factory()->for($first)->create(['quantity' => 3]);
    CartItem::factory()->for($item->cart)->for($second)->create(['quantity' => 2]);
    $order = app(CheckoutService::class)->checkout($item->cart->user)->order;
    $snapshots = $order->items->toArray();
    $first->update(['status' => ProductStatus::Inactive, 'price_minor' => 1234]);
    $this->withToken($item->cart->user->createToken('orders')->plainTextToken);

    $response = $this->postJson('/api/orders/'.$order->id.'/cancel', ['user_id' => PHP_INT_MAX, 'quantity' => 100, 'status' => 'placed'])
        ->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect($response->json('data.cancelled_at'))->not->toBeNull()->toBe($response->json('data.inventory_restored_at'));
    expect($first->fresh()->stock_quantity)->toBe(10);
    expect($second->fresh()->stock_quantity)->toBe(8);
    expect($first->fresh()->price_minor)->toBe(1234);
    expect($order->fresh()->items->toArray())->toBe($snapshots);
    $this->assertDatabaseCount('cart_items', 0);
    $this->assertDatabaseCount('orders', 1);
    $this->travel(1)->hour();
    $this->postJson('/api/orders/'.$order->id.'/cancel')->assertOk()->assertExactJson($response->json());
    expect($first->fresh()->stock_quantity)->toBe(10);
    expect($second->fresh()->stock_quantity)->toBe(8);
    $this->getJson('/api/orders/'.$order->id)->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->getJson('/api/orders')->assertOk()->assertJsonPath('data.0.status', 'cancelled');
});

it('keeps promotion usage counted and replays the original checkout key as the cancelled order without another purchase', function () {
    $product = Product::factory()->create(['stock_quantity' => 10, 'price_minor' => 1000]);
    $item = CartItem::factory()->for($product)->create(['quantity' => 3]);
    $promotion = Promotion::factory()->fixed(500)->limited(1, 1)->create();
    $item->cart->promotion()->associate($promotion)->save();
    $order = app(CheckoutService::class)->checkout($item->cart->user, 'old-checkout-key')->order;
    $ledger = $order->redemption->toArray();
    $this->withToken($item->cart->user->createToken('orders')->plainTextToken);
    $cancelled = $this->postJson('/api/orders/'.$order->id.'/cancel')->assertOk()->json();
    $refilled = CartItem::factory()->for($item->cart)->for($product)->create(['quantity' => 2]);

    $this->postJson('/api/checkout', [], ['Idempotency-Key' => 'old-checkout-key'])->assertOk()->assertExactJson($cancelled);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('promotion_redemptions', 1);
    expect($order->redemption->fresh()->toArray())->toBe($ledger);
    expect($product->fresh()->stock_quantity)->toBe(10);
    $this->assertModelExists($refilled);
    $this->postJson('/api/cart/promotion', ['code' => $promotion->code])->assertConflict()
        ->assertJsonPath('error.code', 'PROMOTION_GLOBAL_USAGE_LIMIT_REACHED');
});
