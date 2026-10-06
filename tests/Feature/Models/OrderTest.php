<?php

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Repositories\PromotionRepositoryInterface;
use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;
use App\Repositories\Eloquent\EloquentOrderRepository;
use App\Services\Checkout\CheckoutService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

it('binds order persistence and exposes typed historical relationships', function () {
    $item = CartItem::factory()->create();
    $promotion = Promotion::factory()->create();
    $item->cart->promotion()->associate($promotion)->save();

    $order = app(CheckoutService::class)->checkout($item->cart->user)->order;

    expect(app(OrderRepositoryInterface::class))->toBeInstanceOf(EloquentOrderRepository::class);
    expect($order->status)->toBe(OrderStatus::Placed);
    expect($order->subtotal_minor)->toBeInt();
    expect($order->placed_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($order->user->orders->sole()->id)->toBe($order->id);
    expect($order->promotion->id)->toBe($promotion->id);
    expect($promotion->orders->sole()->id)->toBe($order->id);
    expect($order->items->sole()->product->id)->toBe($item->product_id);
    expect($item->product->orderItems->sole()->id)->toBe($order->items->sole()->id);
    expect($order->items->sole()->order->id)->toBe($order->id);
    expect($order->redemption->order->id)->toBe($order->id);
});

it('enforces order status money currency key snapshot and ownership integrity', function (array $changes, string $constraint) {
    $order = Order::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('orders')->where('id', $order->id)->update($changes)))
        ->toThrow(QueryException::class, $constraint);

    $this->assertModelExists($order);
})->with([
    [['status' => 'pending'], 'orders_status_valid'],
    [['currency' => 'usd'], 'orders_currency_valid'],
    [['subtotal_minor' => -1], 'orders_money_valid'],
    [['discount_minor' => -1], 'orders_money_valid'],
    [['discount_minor' => 1001], 'orders_money_valid'],
    [['total_minor' => -1], 'orders_money_valid'],
    [['total_minor' => 999], 'orders_money_valid'],
    [['idempotency_key' => 'bad key'], 'orders_idempotency_key_valid'],
    [['promotion_code_snapshot' => 'FAKE'], 'orders_promotion_snapshot_valid'],
    [['user_id' => PHP_INT_MAX], 'orders_user_id_foreign'],
    [['promotion_id' => PHP_INT_MAX, 'promotion_code_snapshot' => 'FAKE', 'promotion_type_snapshot' => 'fixed', 'promotion_value_snapshot' => 1], 'orders_promotion_id_foreign'],
]);

it('enforces positive exact order item amounts uniqueness and real references', function (array $changes, string $constraint) {
    $item = OrderItem::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('order_items')->where('id', $item->id)->update($changes)))
        ->toThrow(QueryException::class, $constraint);

    $this->assertModelExists($item);
})->with([
    [['quantity' => 0, 'unit_price_minor' => 0, 'line_subtotal_minor' => 0], 'order_items_quantity_positive'],
    [['quantity' => -1, 'unit_price_minor' => 0, 'line_subtotal_minor' => 0], 'order_items_quantity_positive'],
    [['unit_price_minor' => -1], 'order_items_money_valid'],
    [['line_subtotal_minor' => -1], 'order_items_money_valid'],
    [['line_subtotal_minor' => 999], 'order_items_money_valid'],
    [['quantity' => PHP_INT_MAX, 'unit_price_minor' => PHP_INT_MAX], 'order_items_money_valid'],
    [['order_id' => PHP_INT_MAX], 'order_items_order_id_foreign'],
    [['product_id' => PHP_INT_MAX], 'order_items_product_id_foreign'],
]);

it('rejects duplicate order item products', function () {
    $item = OrderItem::factory()->create();

    expect(fn () => DB::transaction(fn () => OrderItem::factory()->for($item->order)->for($item->product)->create()))
        ->toThrow(QueryException::class, 'order_items_order_id_product_id_unique');
    $this->assertDatabaseCount('order_items', 1);
});

it('allows absent keys but forbids duplicate customer keys and permits another customer to use the key', function () {
    $user = User::factory()->create();
    Order::factory()->count(2)->for($user)->create();
    Order::factory()->for($user)->create(['idempotency_key' => 'scoped']);

    expect(fn () => DB::transaction(fn () => Order::factory()->for($user)->create(['idempotency_key' => 'scoped'])))
        ->toThrow(QueryException::class, 'orders_user_id_idempotency_key_unique');
    Order::factory()->create(['idempotency_key' => 'scoped']);
    Order::factory()->for($user)->create(['idempotency_key' => 'Scoped']);
    $this->assertDatabaseCount('orders', 5);
});

it('prevents duplicate redemptions for a real order and preserves the UUID identity', function () {
    $item = CartItem::factory()->create();
    $promotion = Promotion::factory()->create();
    $item->cart->promotion()->associate($promotion)->save();
    $order = app(CheckoutService::class)->checkout($item->cart->user)->order;
    $originalKey = $order->redemption->redemption_key;

    expect(fn () => DB::transaction(fn () => app(PromotionRepositoryInterface::class)->createRedemption($order, (string) Str::uuid())))
        ->toThrow(QueryException::class, 'promotion_redemptions_order_id_unique');

    expect($order->redemption->fresh()->redemption_key)->toBe($originalKey);
    $this->assertDatabaseCount('promotion_redemptions', 1);
});

it('rejects an orphaned redemption and restricts deletion of its real order', function () {
    $order = Order::factory()->create();
    $redemption = PromotionRedemption::factory()->for($order)->create();

    expect(fn () => DB::transaction(fn () => $redemption->update(['order_id' => PHP_INT_MAX])))
        ->toThrow(QueryException::class, 'promotion_redemptions_order_id_foreign');
    expect(fn () => DB::transaction(fn () => $order->delete()))
        ->toThrow(QueryException::class, 'promotion_redemptions_order_id_foreign');
    $this->assertModelExists($redemption);
    $this->assertModelExists($order);
});

it('retains history when deleting an ordered product order or customer is attempted', function (string $relation, string $constraint) {
    $item = OrderItem::factory()->create();
    $model = match ($relation) {
        'product' => $item->product,
        'order' => $item->order,
        'user' => $item->order->user,
    };

    expect(fn () => DB::transaction(fn () => $model->delete()))->toThrow(QueryException::class, $constraint);

    $this->assertModelExists($item);
    $this->assertModelExists($model);
})->with([
    ['product', 'order_items_product_id_foreign'], ['order', 'order_items_order_id_foreign'], ['user', 'orders_user_id_foreign'],
]);

it('adds order linkage without changing preexisting legacy UUID discount and usage records', function () {
    $migrationPath = base_path('database/migrations/2026_10_06_151223_add_order_id_to_promotion_redemptions_table.php');
    $migration = require $migrationPath;
    $migration->down();
    $legacy = PromotionRedemption::factory()->create(['discount_minor' => 321]);
    $uuid = $legacy->redemption_key;

    $migration->up();

    expect($legacy->fresh()->order_id)->toBeNull();
    expect($legacy->fresh()->redemption_key)->toBe($uuid);
    expect($legacy->fresh()->discount_minor)->toBe(321);
    expect(app(PromotionRepositoryInterface::class)->redemptionCounts($legacy->promotion, $legacy->user_id))
        ->toBe(['global' => 1, 'customer' => 1]);
    $this->assertDatabaseCount('orders', 0);
});

it('conditionally deducts inventory and rejects invalid internal deduction quantities', function () {
    $product = Product::factory()->create(['stock_quantity' => 5]);
    $repository = app(ProductRepositoryInterface::class);

    expect($repository->deductStock($product, 6))->toBeFalse();
    expect($product->fresh()->stock_quantity)->toBe(5);
    expect(fn () => $repository->deductStock($product, 0))->toThrow(InvalidArgumentException::class);
    expect($repository->deductStock($product, 5))->toBeTrue();
    expect($product->fresh()->stock_quantity)->toBe(0);
});
