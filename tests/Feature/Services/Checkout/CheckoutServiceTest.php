<?php

use App\Contracts\Repositories\CartRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\PromotionRepositoryInterface;
use App\Enums\PromotionIneligibilityReason;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;
use App\Repositories\Eloquent\EloquentCartRepository;
use App\Repositories\Eloquent\EloquentProductRepository;
use App\Repositories\Eloquent\EloquentPromotionRepository;
use App\Services\Checkout\CheckoutService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(LazilyRefreshDatabase::class);

it('rolls back order items inventory usage and cart at every critical write boundary and safely returns 500', function (string $event) {
    config(['app.debug' => true]);
    $first = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 5]))->create(['quantity' => 2]);
    $second = CartItem::factory()->for($first->cart)->for(Product::factory()->create(['stock_quantity' => 7]))->create(['quantity' => 3]);
    $promotion = Promotion::factory()->limited(1, 1)->create();
    $first->cart->promotion()->associate($promotion)->save();
    Event::listen($event, function (): void {
        throw new RuntimeException('Injected failure with private database details.');
    });

    $response = $this->withToken($first->cart->user->createToken('checkout')->plainTextToken)
        ->postJson('/api/checkout', [], ['Idempotency-Key' => 'failed-attempt'])
        ->assertInternalServerError()->assertJsonPath('error.code', 'INTERNAL_ERROR')
        ->assertJsonMissingPath('exception')->assertJsonMissingPath('error.details');

    expect($response->getContent())->not->toContain('Injected failure', 'private database', 'trace', 'SQLSTATE');
    expect($response->json('error.request_id'))->toBe($response->headers->get('X-Request-ID'));
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_outbox_events', 0);
    $this->assertDatabaseCount('order_notifications', 0);
    $this->assertDatabaseCount('order_items', 0);
    $this->assertDatabaseCount('promotion_redemptions', 0);
    $this->assertDatabaseCount('cart_items', 2);
    expect($first->fresh()->quantity)->toBe(2);
    expect($second->fresh()->quantity)->toBe(3);
    expect($first->product->fresh()->stock_quantity)->toBe(5);
    expect($second->product->fresh()->stock_quantity)->toBe(7);
    expect($first->cart->fresh()->promotion_id)->toBe($promotion->id);

    Event::forget($event);
    $this->app['auth']->forgetGuards();
    $this->withToken($first->cart->user->createToken('retry')->plainTextToken)
        ->postJson('/api/checkout', [], ['Idempotency-Key' => 'failed-attempt'])->assertCreated();
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 2);
    $this->assertDatabaseCount('promotion_redemptions', 1);
})->with([
    'order creation' => 'eloquent.created: '.Order::class,
    'item creation' => 'eloquent.created: '.OrderItem::class,
    'after stock deduction' => 'eloquent.creating: '.PromotionRedemption::class,
    'after redemption insertion' => 'eloquent.created: '.PromotionRedemption::class,
    'after cart items deletion' => 'eloquent.updating: '.Cart::class,
]);

it('rolls back a database integrity failure and returns a safe 409 checkout conflict', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 5]))->create();
    Event::listen('eloquent.created: '.Order::class, function (Order $order): void {
        DB::table('orders')->where('id', $order->id)->update(['total_minor' => -1]);
    });

    $response = $this->withToken($item->cart->user->createToken('checkout')->plainTextToken)->postJson('/api/checkout')
        ->assertConflict()->assertJsonPath('error.code', 'CHECKOUT_CONFLICT');

    expect($response->getContent())->not->toContain('SQLSTATE', 'orders_money_valid', 'trace');
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_outbox_events', 0);
    $this->assertDatabaseCount('order_notifications', 0);
    $this->assertDatabaseCount('order_items', 0);
    $this->assertModelExists($item);
    expect($item->product->fresh()->stock_quantity)->toBe(5);
});

it('rolls back preceding inventory deductions if a later conditional deduction fails', function () {
    $first = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 5]))->create();
    $second = CartItem::factory()->for($first->cart)->for(Product::factory()->create(['stock_quantity' => 5]))->create();
    $repository = Mockery::mock(EloquentProductRepository::class)->makePartial();
    $repository->shouldReceive('deductStock')->with(Mockery::on(fn (Product $product): bool => $product->id === $second->product_id), 1)->once()->andReturnFalse();
    $this->app->instance(ProductRepositoryInterface::class, $repository);

    $this->withToken($first->cart->user->createToken('checkout')->plainTextToken)->postJson('/api/checkout')
        ->assertConflict()->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');

    expect($first->product->fresh()->stock_quantity)->toBe(5);
    expect($second->product->fresh()->stock_quantity)->toBe(5);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_outbox_events', 0);
    $this->assertDatabaseCount('order_notifications', 0);
    $this->assertDatabaseCount('order_items', 0);
    $this->assertDatabaseCount('cart_items', 2);
});

it('handles a missing locked product without saving a partial purchase', function () {
    $item = CartItem::factory()->create();
    $repository = Mockery::mock(EloquentProductRepository::class)->makePartial();
    $repository->shouldReceive('lockByIds')->once()->andReturn(new Collection);
    $this->app->instance(ProductRepositoryInterface::class, $repository);

    $this->withToken($item->cart->user->createToken('checkout')->plainTextToken)->postJson('/api/checkout')
        ->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');

    $this->assertModelExists($item);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_outbox_events', 0);
    $this->assertDatabaseCount('order_notifications', 0);
});

it('handles an unresolved selected promotion without consuming inventory or usage', function () {
    $item = CartItem::factory()->create();
    $promotion = Promotion::factory()->create();
    $item->cart->promotion()->associate($promotion)->save();
    $stock = $item->product->stock_quantity;
    $repository = Mockery::mock(EloquentPromotionRepository::class)->makePartial();
    $repository->shouldReceive('findByIdForUpdate')->once()->with($promotion->id)->andReturnNull();
    $this->app->instance(PromotionRepositoryInterface::class, $repository);

    $this->withToken($item->cart->user->createToken('checkout')->plainTextToken)->postJson('/api/checkout')
        ->assertNotFound()->assertJsonPath('error.code', PromotionIneligibilityReason::Unknown->value);

    $this->assertModelExists($item);
    expect($item->cart->fresh()->promotion_id)->toBe($promotion->id);
    expect($item->product->fresh()->stock_quantity)->toBe($stock);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_outbox_events', 0);
    $this->assertDatabaseCount('order_notifications', 0);
    $this->assertDatabaseCount('promotion_redemptions', 0);
});

it('returns 404 when a repository supplies a cart belonging to another customer', function () {
    $item = CartItem::factory()->create();
    $otherUser = User::factory()->create();
    $repository = Mockery::mock(EloquentCartRepository::class)->makePartial();
    $repository->shouldReceive('lockForUser')->once()->andReturn($item->cart);
    $this->app->instance(CartRepositoryInterface::class, $repository);

    $this->withToken($otherUser->createToken('checkout')->plainTextToken)->postJson('/api/checkout')->assertNotFound();

    $this->assertModelExists($item);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_outbox_events', 0);
    $this->assertDatabaseCount('order_notifications', 0);
});

it('uses one transaction locks cart then ascending products then promotion and counts before inserting redemption', function () {
    $first = Product::factory()->create(['stock_quantity' => 5]);
    $second = Product::factory()->create(['stock_quantity' => 5]);
    $item = CartItem::factory()->for($second)->create();
    CartItem::factory()->for($item->cart)->for($first)->create();
    $promotion = Promotion::factory()->limited(2, 1)->create();
    $item->cart->promotion()->associate($promotion)->save();
    $startingLevel = DB::transactionLevel();
    $levels = [];
    DB::listen(function ($query) use (&$levels): void {
        if (str_contains($query->sql, 'for update') || str_contains($query->sql, 'COUNT(*)')) {
            $levels[] = DB::transactionLevel();
        }
    });
    DB::enableQueryLog();

    $result = app(CheckoutService::class)->checkout($item->cart->user, 'lock-order');

    $queries = collect(DB::getQueryLog());
    $locks = $queries->filter(fn (array $query): bool => str_contains($query['query'], 'for update'))->values();
    expect($locks)->toHaveCount(3);
    expect($locks[0]['query'])->toContain('"carts"');
    expect($locks[1]['query'])->toContain('"products"', 'order by "id" asc');
    expect($locks[2]['query'])->toContain('"promotions"');
    expect($levels)->toBe([$startingLevel + 1, $startingLevel + 1, $startingLevel + 1, $startingLevel + 1]);
    $countPosition = $queries->search(fn (array $query): bool => str_contains($query['query'], 'COUNT(*)'));
    $redemptionPosition = $queries->search(fn (array $query): bool => str_starts_with($query['query'], 'insert into "promotion_redemptions"'));
    expect($countPosition)->toBeLessThan($redemptionPosition);
    expect(DB::transactionLevel())->toBe($startingLevel);
    expect($result->order->items)->toHaveCount(2);
    DB::disableQueryLog();
});
