<?php

use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromotionRedemption;
use App\Models\User;
use App\Services\Checkout\CheckoutService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

/** Remove committed fixtures before the intentionally guarded placed-only downgrade. */
afterEach(function (): void {
    DB::statement('TRUNCATE TABLE orders CASCADE');
});

/**
 * Reuse the committed-fixture, HTTP-kernel worker and observed PostgreSQL lock barrier.
 * Both independent workers must be actively waiting on the barrier before release.
 *
 * @param  array<int, array{user: User, path: string}>  $requests
 * @return array<int, array{status: int, body: array<string, mixed>, pid: int, database: string, locks: array<int, string>, order_updates: int, inventory_restores: int}>
 */
function simultaneousOrderRequests(array $requests, Model $barrier): array
{
    expect(DB::transactionLevel())->toBe(0);
    expect(DB::selectOne('SHOW transaction_isolation')->transaction_isolation)->toBe('read committed');
    $connectionConfig = config('database.connections.pgsql');
    config(['database.connections.order_observer' => $connectionConfig]);
    $environment = [
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
        'DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'postgres', 'DB_PORT' => '5432',
        'DB_DATABASE' => 'ecommerce_order_api_test', 'DB_USERNAME' => $connectionConfig['username'],
        'DB_PASSWORD' => $connectionConfig['password'], 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    ];
    $run = 'order-concurrency-'.bin2hex(random_bytes(8));
    $names = [$run.'-1', $run.'-2'];
    $workers = [];
    $waiting = [];
    $tokens = array_map(fn (array $request): string => $request['user']->createToken('order-concurrency')->plainTextToken, $requests);
    DB::beginTransaction();

    try {
        $barrier->newQuery()->lockForUpdate()->findOrFail($barrier->getKey());

        foreach ($requests as $index => $request) {
            $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/cart-promotion-request.php')], base_path(), $environment);
            $worker->setInput(json_encode([
                'application_name' => $names[$index], 'token' => $tokens[$index],
                'method' => 'POST', 'path' => $request['path'], 'body' => [],
            ], JSON_THROW_ON_ERROR));
            $worker->setTimeout(15);
            $worker->start();
            $workers[] = $worker;
        }

        $deadline = microtime(true) + 5;

        do {
            $waiting = DB::connection('order_observer')->select(
                'SELECT pid, query FROM pg_stat_activity WHERE application_name IN (?, ?) AND wait_event_type = ? AND state = ?',
                [$names[0], $names[1], 'Lock', 'active'],
            );

            if (count($waiting) === 2) {
                break;
            }

            usleep(10000);
        } while (microtime(true) < $deadline && $workers[0]->isRunning() && $workers[1]->isRunning());

        expect($waiting)->toHaveCount(2);
        expect(array_unique(array_column($waiting, 'pid')))->toHaveCount(2);

        foreach ($waiting as $waiter) {
            expect($waiter->query)->toContain('"'.$barrier->getTable().'"', 'for update');
        }

        DB::rollBack();
        $results = [];

        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }

        expect($results[0]['pid'])->not->toBe($results[1]['pid']);
        expect(array_column($results, 'database'))->toBe(['ecommerce_order_api_test', 'ecommerce_order_api_test']);
        $evidence = [
            'barrier' => $barrier->getTable(), 'waiting_pids' => array_column($waiting, 'pid'),
            'worker_pids' => array_column($results, 'pid'), 'statuses' => array_column($results, 'status'),
            'order_ids' => array_map(fn (array $result): ?int => $result['body']['data']['id'] ?? null, $results),
            'orders' => Order::query()->count(),
            'order_updates' => array_column($results, 'order_updates'),
            'inventory_restores' => array_column($results, 'inventory_restores'), 'redemptions' => PromotionRedemption::query()->count(),
            'stock' => Product::query()->orderBy('id')->pluck('stock_quantity')->all(),
        ];
        fwrite(STDOUT, "\nOrder concurrency evidence: ".json_encode($evidence, JSON_THROW_ON_ERROR)."\n");

        return $results;
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }

        DB::purge('order_observer');
    }
}

it('restores once and transitions once for two observed concurrent cancellations by the same customer', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 10]))->create(['quantity' => 3]);
    $order = app(CheckoutService::class)->checkout($item->cart->user)->order;
    $request = ['user' => $item->cart->user, 'path' => '/api/orders/'.$order->id.'/cancel'];

    $results = simultaneousOrderRequests([$request, $request], $order);

    expect(array_column($results, 'status'))->toBe([200, 200]);
    expect($results[0]['body'])->toBe($results[1]['body']);
    expect(array_sum(array_column($results, 'order_updates')))->toBe(1);
    expect(array_sum(array_column($results, 'inventory_restores')))->toBe(1);
    expect($item->product->fresh()->stock_quantity)->toBe(10);
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    expect($order->fresh()->cancelled_at)->not->toBeNull();
    expect($order->fresh()->inventory_restored_at->equalTo($order->fresh()->cancelled_at))->toBeTrue();
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 1);
    $this->assertDatabaseCount('cart_items', 0);
});

it('preserves inventory for cancellation overlapping another customer checkout with or without stock before restoration', function (int $startingStock, int $orderedQuantity, int $purchaseQuantity) {
    $product = Product::factory()->create(['stock_quantity' => $startingStock]);
    $item = CartItem::factory()->for($product)->create(['quantity' => $orderedQuantity]);
    $order = app(CheckoutService::class)->checkout($item->cart->user)->order;
    $buyer = CartItem::factory()->for($product)->create(['quantity' => $purchaseQuantity]);

    $results = simultaneousOrderRequests([
        ['user' => $item->cart->user, 'path' => '/api/orders/'.$order->id.'/cancel'],
        ['user' => $buyer->cart->user, 'path' => '/api/checkout'],
    ], $product);

    expect($results[0]['status'])->toBe(200);
    if ($startingStock - $orderedQuantity >= $purchaseQuantity) {
        expect($results[1]['status'])->toBe(201);
    } else {
        expect($results[1]['status'])->toBeIn([201, 409]);
    }
    $purchased = $results[1]['status'] === 201;
    if (! $purchased) {
        expect($results[1]['body']['error']['code'])->toBe('INSUFFICIENT_STOCK');
        $this->assertModelExists($buyer);
    } else {
        $this->assertModelMissing($buyer);
    }
    expect($product->fresh()->stock_quantity)->toBe($startingStock - ($purchased ? $purchaseQuantity : 0))->toBeGreaterThanOrEqual(0);
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    expect($order->fresh()->inventory_restored_at->equalTo($order->fresh()->cancelled_at))->toBeTrue();
    expect(array_sum(array_column($results, 'inventory_restores')))->toBe(1);
    $this->assertDatabaseCount('orders', $purchased ? 2 : 1);
    $this->assertDatabaseCount('order_items', $purchased ? 2 : 1);
})->with(['stock available' => [10, 3, 4], 'must wait for restoration or conflict' => [5, 5, 3]]);

it('locks overlapping products in ascending order for multiple-product cancellation against reverse-insertion checkout', function () {
    $firstProduct = Product::factory()->create(['stock_quantity' => 10]);
    $secondProduct = Product::factory()->create(['stock_quantity' => 10]);
    $item = CartItem::factory()->for($secondProduct)->create(['quantity' => 3]);
    CartItem::factory()->for($item->cart)->for($firstProduct)->create(['quantity' => 2]);
    $order = app(CheckoutService::class)->checkout($item->cart->user)->order;
    $buyer = CartItem::factory()->for($secondProduct)->create(['quantity' => 4]);
    CartItem::factory()->for($buyer->cart)->for($firstProduct)->create(['quantity' => 5]);

    $results = simultaneousOrderRequests([
        ['user' => $item->cart->user, 'path' => '/api/orders/'.$order->id.'/cancel'],
        ['user' => $buyer->cart->user, 'path' => '/api/checkout'],
    ], $firstProduct);

    expect(array_column($results, 'status'))->toBe([200, 201]);
    foreach ($results as $index => $result) {
        expect($result['locks'])->toHaveCount(2);
        expect($result['locks'][0])->toContain($index === 0 ? '"orders"' : '"carts"');
        expect($result['locks'][1])->toContain('"products"', 'order by "id" asc');
    }
    expect($firstProduct->fresh()->stock_quantity)->toBe(5);
    expect($secondProduct->fresh()->stock_quantity)->toBe(6);
    expect(array_sum(array_column($results, 'inventory_restores')))->toBe(2);
    expect(array_sum(array_column($results, 'order_updates')))->toBe(1);
    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    $this->assertDatabaseCount('orders', 2);
    $this->assertDatabaseCount('order_items', 4);
    $this->assertDatabaseCount('cart_items', 0);
});

it('retries the entire cancellation for injected deadlocks and fully rolls back exhausted attempts', function (int $failures) {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 10]))->create(['quantity' => 3]);
    $order = app(CheckoutService::class)->checkout($item->cart->user)->order;
    $attempts = 0;
    Event::listen('eloquent.updated: '.Order::class, function () use (&$attempts, $failures): void {
        $attempts++;
        if ($attempts <= $failures) {
            DB::statement("DO 'BEGIN RAISE EXCEPTION ''deadlock detected (injected)'' USING ERRCODE = ''40P01''; END'");
        }
    });

    $response = $this->withToken($item->cart->user->createToken('orders')->plainTextToken)->postJson('/api/orders/'.$order->id.'/cancel');

    expect($attempts)->toBe(3);
    expect(DB::transactionLevel())->toBe(0);
    if ($failures === 2) {
        $response->assertOk()->assertJsonPath('data.status', 'cancelled');
        expect($item->product->fresh()->stock_quantity)->toBe(10);
        expect($order->fresh()->inventory_restored_at->equalTo($order->fresh()->cancelled_at))->toBeTrue();
    } else {
        $response->assertConflict()->assertJsonPath('error.code', 'ORDER_CANCELLATION_CONFLICT');
        expect($item->product->fresh()->stock_quantity)->toBe(7);
        expect($order->fresh()->status)->toBe(OrderStatus::Placed);
        expect($order->fresh()->cancelled_at)->toBeNull();
        expect($order->fresh()->inventory_restored_at)->toBeNull();
    }
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 1);
})->with(['two deadlocks then success' => 2, 'three deadlocks exhaust retries' => 3]);
