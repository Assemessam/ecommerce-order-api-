<?php

use App\Enums\OrderEventType;
use App\Enums\OrderOutboxStatus;
use App\Models\CartItem;
use App\Models\OrderOutboxEvent;
use App\Models\Product;
use App\Models\Promotion;
use App\Services\Checkout\CheckoutService;
use App\Services\Order\OrderOutboxService;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

it('records immutable placed and cancelled envelopes once across business replays', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 10]))->create(['quantity' => 2]);
    $promotion = Promotion::factory()->create();
    $item->cart->promotion()->associate($promotion)->save();
    $checkout = app(CheckoutService::class);
    $order = $checkout->checkout($item->cart->user, 'event-replay')->order;
    expect($checkout->checkout($item->cart->user, 'event-replay')->isReplay)->toBeTrue();
    $placed = OrderOutboxEvent::query()->sole();
    expect(Str::isUuid($placed->id))->toBeTrue();
    expect($placed->order_id)->toBe($order->id);
    expect($placed->event_type)->toBe(OrderEventType::OrderPlaced);
    expect($placed->occurred_at->equalTo($order->placed_at))->toBeTrue();
    expect($placed->status)->toBe(OrderOutboxStatus::Pending);
    expect($placed->dispatch_token)->toBeNull();
    $this->assertDatabaseCount('order_notifications', 0);
    $this->assertDatabaseCount('promotion_redemptions', 1);

    $cancelled = app(OrderService::class)->cancelOrder($item->cart->user, (string) $order->id);
    app(OrderService::class)->cancelOrder($item->cart->user, (string) $order->id);
    $event = OrderOutboxEvent::query()->where('event_type', OrderEventType::OrderCancelled)->sole();
    expect($event->order_id)->toBe($order->id);
    expect($event->occurred_at->equalTo($cancelled->cancelled_at))->toBeTrue();
    expect($item->product->fresh()->stock_quantity)->toBe(10);
    $this->assertDatabaseCount('order_outbox_events', 2);
    $this->assertDatabaseCount('promotion_redemptions', 1);
    $this->assertDatabaseCount('order_notifications', 0);
});

it('rolls back checkout completely if durable event persistence fails', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 10]))->create(['quantity' => 2]);
    $promotion = Promotion::factory()->create();
    $item->cart->promotion()->associate($promotion)->save();
    Event::listen('eloquent.created: '.OrderOutboxEvent::class, function (): never {
        throw new RuntimeException('Injected outbox persistence failure');
    });
    expect(fn () => app(CheckoutService::class)->checkout($item->cart->user))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
    $this->assertDatabaseCount('promotion_redemptions', 0);
    $this->assertDatabaseCount('order_outbox_events', 0);
    $this->assertDatabaseCount('order_notifications', 0);
    $this->assertModelExists($item);
    expect($item->product->fresh()->stock_quantity)->toBe(10);
    expect($item->cart->fresh()->promotion_id)->toBe($promotion->id);
});

it('rolls back cancellation restoration and markers if its event persistence fails', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 10]))->create(['quantity' => 2]);
    $order = app(CheckoutService::class)->checkout($item->cart->user)->order;
    Event::listen('eloquent.created: '.OrderOutboxEvent::class, function (): never {
        throw new RuntimeException('Injected outbox persistence failure');
    });
    expect(fn () => app(OrderService::class)->cancelOrder($item->cart->user, (string) $order->id))->toThrow(RuntimeException::class);
    expect($order->fresh()->cancelled_at)->toBeNull();
    expect($order->fresh()->inventory_restored_at)->toBeNull();
    expect($item->product->fresh()->stock_quantity)->toBe(8);
    $this->assertDatabaseCount('order_outbox_events', 1);
    $this->assertDatabaseCount('order_notifications', 0);
});

it('does not retain either business event when an enclosing transaction rolls back', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 10]))->create(['quantity' => 2]);
    expect(function () use ($item): void {
        DB::transaction(function () use ($item): void {
            $order = app(CheckoutService::class)->checkout($item->cart->user)->order;
            app(OrderService::class)->cancelOrder($item->cart->user, (string) $order->id);
            throw new RuntimeException('Rollback outer transaction');
        });
    })->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_outbox_events', 0);
    $this->assertDatabaseCount('order_notifications', 0);
    expect($item->product->fresh()->stock_quantity)->toBe(10);
    $this->assertModelExists($item);
});

it('refuses to dispatch inside an open transaction', function () {
    OrderOutboxEvent::factory()->create();
    expect(fn () => app(OrderOutboxService::class)->dispatchDue(10))->toThrow(LogicException::class);
    expect(OrderOutboxEvent::query()->sole()->status)->toBe(OrderOutboxStatus::Pending);
});

it('allows safe manual recovery but refuses active leases and processed effects', function () {
    $event = OrderOutboxEvent::factory()->queued()->create();
    $outbox = app(OrderOutboxService::class);
    expect($outbox->retry($event->id))->toBeFalse();
    $event->update(['available_at' => now('UTC')->subSecond()]);
    expect($outbox->retry($event->id))->toBeTrue();
    expect($event->fresh()->status)->toBe(OrderOutboxStatus::Pending);
    expect($event->fresh()->dispatch_token)->toBeNull();
    $event->update(['status' => OrderOutboxStatus::Failed, 'dispatch_token' => (string) Str::uuid(),
        'failed_at' => now('UTC'), 'processing_attempts' => 3, 'last_error' => RuntimeException::class]);
    expect($outbox->retry($event->id))->toBeTrue();
    expect($event->fresh()->processing_attempts)->toBe(0);
    expect($event->fresh()->failed_at)->toBeNull();
    $event->update(['status' => OrderOutboxStatus::Processed, 'dispatch_token' => (string) Str::uuid(), 'processed_at' => now('UTC')]);
    expect($outbox->retry($event->id))->toBeFalse();
    expect($outbox->retry((string) Str::uuid()))->toBeFalse();
});

it('exposes bounded status and sanitized failure diagnostics through CLI commands', function () {
    $event = OrderOutboxEvent::factory()->queued()->create(['status' => OrderOutboxStatus::Failed, 'failed_at' => now('UTC'), 'last_error' => RuntimeException::class]);
    $this->artisan('orders:outbox', ['--status' => 'failed'])->expectsOutputToContain($event->id)->assertSuccessful();
    expect(app(OrderOutboxService::class)->inspect('failed', 100)->sole()->last_error)->toBe(RuntimeException::class);
    $this->artisan('orders:retry-outbox', ['eventId' => $event->id])->assertSuccessful();
    expect($event->fresh()->status)->toBe(OrderOutboxStatus::Pending);
    $this->artisan('orders:retry-outbox', ['eventId' => 'invalid'])->assertFailed();
    $this->artisan('orders:outbox', ['--status' => 'invalid'])->assertFailed();
    $this->artisan('orders:outbox', ['--limit' => 1001])->assertFailed();
    $this->artisan('orders:dispatch-outbox', ['--limit' => 0])->assertFailed();
});
