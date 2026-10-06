<?php

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Services\Checkout\CheckoutService;
use App\Services\Order\OrderService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

it('enforces cancelled status and equal non-null restoration timestamps together', function (array $changes) {
    $order = Order::factory()->create();
    expect(fn () => DB::transaction(fn () => DB::table('orders')->where('id', $order->id)->update($changes)))
        ->toThrow(QueryException::class, 'orders_cancellation_state_valid');
    expect($order->fresh()->status)->toBe(OrderStatus::Placed);
    expect($order->fresh()->cancelled_at)->toBeNull();
    expect($order->fresh()->inventory_restored_at)->toBeNull();
})->with([
    [['status' => 'cancelled']],
    [['status' => 'cancelled', 'cancelled_at' => '2026-01-01 00:00:00+00']],
    [['status' => 'cancelled', 'inventory_restored_at' => '2026-01-01 00:00:00+00']],
    [['cancelled_at' => '2026-01-01 00:00:00+00']],
    [['inventory_restored_at' => '2026-01-01 00:00:00+00']],
    [['status' => 'cancelled', 'cancelled_at' => '2026-01-01 00:00:00+00', 'inventory_restored_at' => '2026-01-02 00:00:00+00']],
]);

it('restores inventory safely up to the exact signed bigint maximum including a maximum purchased quantity', function () {
    $product = Product::factory()->create(['stock_quantity' => PHP_INT_MAX, 'price_minor' => 0]);
    $item = CartItem::factory()->for($product)->create(['quantity' => PHP_INT_MAX]);
    $order = app(CheckoutService::class)->checkout($item->cart->user)->order;
    expect($product->fresh()->stock_quantity)->toBe(0);
    app(OrderService::class)->cancelOrder($item->cart->user, (string) $order->id);
    expect($product->fresh()->stock_quantity)->toBe(PHP_INT_MAX);
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    expect($order->fresh()->cancelled_at->equalTo($order->fresh()->inventory_restored_at))->toBeTrue();
});

it('rejects invalid internal restoration quantities and conditional overflow without changing stock', function () {
    $product = Product::factory()->create(['stock_quantity' => PHP_INT_MAX - 1]);
    $repository = app(ProductRepositoryInterface::class);
    expect(fn () => $repository->restoreStock($product, 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $repository->restoreStock($product, -1))->toThrow(InvalidArgumentException::class);
    expect($repository->restoreStock($product, 2))->toBeFalse();
    expect($product->fresh()->stock_quantity)->toBe(PHP_INT_MAX - 1);
    expect($repository->restoreStock($product, 1))->toBeTrue();
    expect($product->fresh()->stock_quantity)->toBe(PHP_INT_MAX);
});

it('upgrades existing purchase snapshots and redemptions additively without changing historical data', function () {
    $migration = require base_path('database/migrations/2026_10_06_153712_add_cancellation_metadata_to_orders_table.php');
    $migration->down();
    $item = CartItem::factory()->create();
    $promotion = Promotion::factory()->create();
    $item->cart->promotion()->associate($promotion)->save();
    $order = app(CheckoutService::class)->checkout($item->cart->user, 'preserved-key')->order;
    $original = $order->getAttributes();
    $snapshots = $order->items->toArray();
    $redemption = $order->redemption->toArray();

    $migration->up();

    expect(array_diff_key($order->fresh()->getAttributes(), ['cancelled_at' => true, 'inventory_restored_at' => true]))->toBe($original);
    expect($order->fresh()->cancelled_at)->toBeNull();
    expect($order->fresh()->inventory_restored_at)->toBeNull();
    expect($order->fresh()->items->toArray())->toBe($snapshots);
    expect($order->redemption->fresh()->toArray())->toBe($redemption);
    app(OrderService::class)->cancelOrder($item->cart->user, (string) $order->id);
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

it('refuses downgrade after a cancellation rather than relabel restored inventory as placed', function () {
    $item = CartItem::factory()->create();
    $order = app(CheckoutService::class)->checkout($item->cart->user)->order;
    app(OrderService::class)->cancelOrder($item->cart->user, (string) $order->id);
    $migration = require base_path('database/migrations/2026_10_06_153712_add_cancellation_metadata_to_orders_table.php');

    expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class, 'orders_status_valid');
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    expect($order->fresh()->inventory_restored_at)->not->toBeNull();
});
