<?php

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Repositories\Eloquent\EloquentOrderRepository;
use App\Repositories\Eloquent\EloquentProductRepository;
use App\Services\Checkout\CheckoutService;
use App\Services\Order\OrderService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(LazilyRefreshDatabase::class);

it('rolls back all stock and markers when cancellation fails at a write boundary and returns a safe 500', function (string $boundary) {
    config(['app.debug' => true]);
    $first = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 10]))->create(['quantity' => 3]);
    $second = CartItem::factory()->for($first->cart)->for(Product::factory()->create(['stock_quantity' => 8]))->create(['quantity' => 2]);
    $order = app(CheckoutService::class)->checkout($first->cart->user)->order;
    $original = $order->toArray();
    $snapshots = $order->items->toArray();
    $failure = static function (): never {
        throw new RuntimeException('Injected failure with private database details.');
    };

    if ($boundary === 'second product') {
        $repository = Mockery::mock(EloquentProductRepository::class)->makePartial();
        $repository->shouldReceive('restoreStock')->with(Mockery::on(fn (Product $product): bool => $product->id === $second->product_id), 2)
            ->once()->andReturnUsing($failure);
        $this->app->instance(ProductRepositoryInterface::class, $repository);
    } elseif ($boundary === 'database failure') {
        Event::listen('eloquent.updated: '.Order::class, static function (): void {
            DB::statement('SELECT 1 / 0');
        });
    } else {
        Event::listen('eloquent.'.$boundary.': '.Order::class, $failure);
    }

    $response = $this->withToken($first->cart->user->createToken('orders')->plainTextToken)->postJson('/api/orders/'.$order->id.'/cancel')
        ->assertInternalServerError()->assertJsonPath('error.code', 'INTERNAL_ERROR')
        ->assertJsonMissingPath('exception')->assertJsonMissingPath('error.details');
    expect($response->getContent())->not->toContain('SQLSTATE', 'trace', 'Injected failure', 'private database');
    expect($response->json('error.request_id'))->toBe($response->headers->get('X-Request-ID'));
    expect($first->product->fresh()->stock_quantity)->toBe(7);
    expect($second->product->fresh()->stock_quantity)->toBe(6);
    expect($order->fresh()->load('items')->toArray())->toBe($original);
    expect($order->fresh()->items->toArray())->toBe($snapshots);
    $this->assertDatabaseCount('cart_items', 0);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_outbox_events', 1);
    $this->assertDatabaseCount('order_notifications', 0);
    $this->assertDatabaseCount('order_items', 2);
})->with(['during inventory restoration' => 'second product', 'after all stock before status' => 'updating', 'after status and markers' => 'updated', 'unexpected database error' => 'database failure']);

it('rolls back earlier restores on stock overflow with a safe 409', function () {
    $first = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 10, 'price_minor' => 1]))->create(['quantity' => 3]);
    $second = CartItem::factory()->for($first->cart)->for(Product::factory()->create(['stock_quantity' => 8, 'price_minor' => 1]))->create(['quantity' => 2]);
    $order = app(CheckoutService::class)->checkout($first->cart->user)->order;
    $second->product->update(['stock_quantity' => PHP_INT_MAX - 1]);

    $this->withToken($first->cart->user->createToken('orders')->plainTextToken)->postJson('/api/orders/'.$order->id.'/cancel')
        ->assertConflict()->assertJsonPath('error.code', 'INVENTORY_RESTORATION_OVERFLOW');

    expect($first->product->fresh()->stock_quantity)->toBe(7);
    expect($second->product->fresh()->stock_quantity)->toBe(PHP_INT_MAX - 1);
    expect($order->fresh()->status)->toBe(OrderStatus::Placed);
    expect($order->fresh()->cancelled_at)->toBeNull();
    expect($order->fresh()->inventory_restored_at)->toBeNull();
});

it('rolls back earlier restores if the later conditional increment refuses the update', function () {
    $first = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 10]))->create(['quantity' => 3]);
    $second = CartItem::factory()->for($first->cart)->for(Product::factory()->create(['stock_quantity' => 8]))->create(['quantity' => 2]);
    $order = app(CheckoutService::class)->checkout($first->cart->user)->order;
    $repository = Mockery::mock(EloquentProductRepository::class)->makePartial();
    $repository->shouldReceive('restoreStock')->with(Mockery::on(fn (Product $product): bool => $product->id === $second->product_id), 2)->once()->andReturnFalse();
    $this->app->instance(ProductRepositoryInterface::class, $repository);

    $this->withToken($first->cart->user->createToken('orders')->plainTextToken)->postJson('/api/orders/'.$order->id.'/cancel')
        ->assertConflict()->assertJsonPath('error.code', 'INVENTORY_RESTORATION_OVERFLOW');
    expect($first->product->fresh()->stock_quantity)->toBe(7);
    expect($second->product->fresh()->stock_quantity)->toBe(6);
    expect($order->fresh()->status)->toBe(OrderStatus::Placed);
    expect($order->fresh()->inventory_restored_at)->toBeNull();
});

it('returns 409 without restoring when an internal order state contradicts cancellation eligibility', function (array $attributes, string $code) {
    $item = CartItem::factory()->create();
    $order = app(CheckoutService::class)->checkout($item->cart->user)->order;
    $stock = $item->product->fresh()->stock_quantity;
    $order->forceFill($attributes);
    $repository = Mockery::mock(EloquentOrderRepository::class)->makePartial();
    $repository->shouldReceive('lockForUser')->once()->andReturn($order);
    $this->app->instance(OrderRepositoryInterface::class, $repository);

    $this->withToken($item->cart->user->createToken('orders')->plainTextToken)->postJson('/api/orders/'.$order->id.'/cancel')
        ->assertConflict()->assertJsonPath('error.code', $code);
    expect($item->product->fresh()->stock_quantity)->toBe($stock);
    expect($order->fresh()->status)->toBe(OrderStatus::Placed);
})->with([
    'placed with restoration' => [['inventory_restored_at' => '2026-01-01 00:00:00+00'], 'INVALID_ORDER_STATUS'],
    'placed with cancellation' => [['cancelled_at' => '2026-01-01 00:00:00+00'], 'INVALID_ORDER_STATUS'],
    'cancelled without restoration' => [['status' => OrderStatus::Cancelled, 'cancelled_at' => '2026-01-01 00:00:00+00'], 'ORDER_CANCELLATION_CONFLICT'],
    'cancelled mismatched markers' => [['status' => OrderStatus::Cancelled, 'cancelled_at' => '2026-01-01 00:00:00+00', 'inventory_restored_at' => '2026-01-02 00:00:00+00'], 'ORDER_CANCELLATION_CONFLICT'],
    'cancelled without cancellation time' => [['status' => OrderStatus::Cancelled, 'inventory_restored_at' => '2026-01-01 00:00:00+00'], 'ORDER_CANCELLATION_CONFLICT'],
]);

it('returns 409 for an incomplete order without products or a missing locked product', function (bool $missingProduct) {
    $order = Order::factory()->create();
    if ($missingProduct) {
        $item = CartItem::factory()->create();
        $order = app(CheckoutService::class)->checkout($item->cart->user)->order;
        $repository = Mockery::mock(EloquentProductRepository::class)->makePartial();
        $repository->shouldReceive('lockByIds')->once()->andReturn(new Collection);
        $this->app->instance(ProductRepositoryInterface::class, $repository);
    }

    $this->withToken($order->user->createToken('orders')->plainTextToken)->postJson('/api/orders/'.$order->id.'/cancel')
        ->assertConflict()->assertJsonPath('error.code', 'ORDER_CANCELLATION_CONFLICT');
    expect($order->fresh()->status)->toBe(OrderStatus::Placed);
    expect($order->fresh()->inventory_restored_at)->toBeNull();
})->with(['no items' => false, 'missing locked product' => true]);

it('authorizes the policy even if a repository supplies another customer order', function (string $method) {
    $item = CartItem::factory()->create();
    $order = app(CheckoutService::class)->checkout($item->cart->user)->order;
    $repository = Mockery::mock(EloquentOrderRepository::class)->makePartial();
    $repository->shouldReceive($method === 'GET' ? 'findForUser' : 'lockForUser')->once()->andReturn($order);
    $this->app->instance(OrderRepositoryInterface::class, $repository);

    $this->withToken(User::factory()->create()->createToken('orders')->plainTextToken)
        ->json($method, '/api/orders/'.$order->id.($method === 'POST' ? '/cancel' : ''))->assertNotFound();
    expect($order->fresh()->status)->toBe(OrderStatus::Placed);
})->with(['GET', 'POST']);

it('maps a real database constraint failure to 409 and rolls back inventory', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 5]))->create(['quantity' => 2]);
    $order = app(CheckoutService::class)->checkout($item->cart->user)->order;
    Event::listen('eloquent.updating: '.Order::class, function (Order $order): void {
        $order->total_minor = -1;
    });

    $response = $this->withToken($item->cart->user->createToken('orders')->plainTextToken)->postJson('/api/orders/'.$order->id.'/cancel')
        ->assertConflict()->assertJsonPath('error.code', 'ORDER_CANCELLATION_CONFLICT');
    expect($response->getContent())->not->toContain('SQLSTATE', 'orders_money_valid');
    expect($item->product->fresh()->stock_quantity)->toBe(3);
    expect($order->fresh()->status)->toBe(OrderStatus::Placed);
    expect($order->fresh()->cancelled_at)->toBeNull();
    expect($order->fresh()->inventory_restored_at)->toBeNull();
});

it('locks order then products ascending in one transaction and repeated cancellation does not lock or write products', function () {
    $first = Product::factory()->create(['stock_quantity' => 10]);
    $second = Product::factory()->create(['stock_quantity' => 10]);
    $item = CartItem::factory()->for($second)->create(['quantity' => 2]);
    CartItem::factory()->for($item->cart)->for($first)->create(['quantity' => 3]);
    $order = app(CheckoutService::class)->checkout($item->cart->user)->order;
    $startingLevel = DB::transactionLevel();
    $levels = [];
    DB::listen(function ($query) use (&$levels): void {
        if (str_contains($query->sql, 'for update')) {
            $levels[] = DB::transactionLevel();
        }
    });
    DB::enableQueryLog();

    app(OrderService::class)->cancelOrder($item->cart->user, (string) $order->id);
    $locks = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'for update'))->values();
    expect($locks)->toHaveCount(2);
    expect($locks[0]['query'])->toContain('"orders"');
    expect($locks[1]['query'])->toContain('"products"', 'order by "id" asc');
    expect($levels)->toBe([$startingLevel + 1, $startingLevel + 1]);
    expect(DB::transactionLevel())->toBe($startingLevel);
    DB::flushQueryLog();
    app(OrderService::class)->cancelOrder($item->cart->user, (string) $order->id);
    $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    expect($queries)->not->toContain('"products"', 'update ', '"carts"', '"promotions"');
    DB::disableQueryLog();
});

it('reads multiple historical items in two queries and listing in two queries without catalogue relationships or writes', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 10]))->create();
    CartItem::factory()->count(3)->for($item->cart)->create();
    $user = $item->cart->user;
    $order = app(CheckoutService::class)->checkout($user)->order;
    DB::enableQueryLog();

    expect(app(OrderService::class)->getOrder($user, (string) $order->id)->items)->toHaveCount(4);
    $queries = collect(DB::getQueryLog())->pluck('query');
    expect($queries)->toHaveCount(2);
    expect($queries->implode(' '))->not->toContain('"products"', '"users"', '"promotions"', 'update ', 'for update');
    DB::flushQueryLog();
    expect(app(OrderService::class)->listOrders($user)->total())->toBe(1);
    $queries = collect(DB::getQueryLog())->pluck('query');
    expect($queries)->toHaveCount(2);
    expect($queries->implode(' '))->not->toContain('"order_items"', '"products"', 'update ', 'for update');
    DB::disableQueryLog();
});
