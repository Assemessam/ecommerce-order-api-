<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

/**
 * Reuse the committed-fixture, HTTP-kernel worker and observed PostgreSQL lock barrier.
 * Both independent workers must be actively waiting on the barrier before release.
 *
 * @param  array<int, Cart>  $carts
 * @return array<int, array{status: int, body: array<string, mixed>, pid: int, database: string, locks: array<int, string>}>
 */
function simultaneousCheckoutRequests(array $carts, Model $barrier, ?string $key = null): array
{
    expect(DB::transactionLevel())->toBe(0);
    expect(DB::selectOne('SHOW transaction_isolation')->transaction_isolation)->toBe('read committed');
    $connectionConfig = config('database.connections.pgsql');
    config(['database.connections.checkout_observer' => $connectionConfig]);
    $environment = [
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
        'DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'postgres', 'DB_PORT' => '5432',
        'DB_DATABASE' => 'ecommerce_order_api_test', 'DB_USERNAME' => $connectionConfig['username'],
        'DB_PASSWORD' => $connectionConfig['password'], 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    ];
    $run = 'checkout-concurrency-'.bin2hex(random_bytes(8));
    $names = [$run.'-1', $run.'-2'];
    $workers = [];
    $waiting = [];
    $tokens = array_map(fn (Cart $cart): string => $cart->user->createToken('checkout-concurrency')->plainTextToken, $carts);
    DB::beginTransaction();

    try {
        $barrier->newQuery()->lockForUpdate()->findOrFail($barrier->getKey());

        foreach ($carts as $index => $cart) {
            $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/cart-promotion-request.php')], base_path(), $environment);
            $worker->setInput(json_encode([
                'application_name' => $names[$index], 'token' => $tokens[$index],
                'method' => 'POST', 'path' => '/api/checkout', 'body' => [], 'idempotency_key' => $key,
            ], JSON_THROW_ON_ERROR));
            $worker->setTimeout(15);
            $worker->start();
            $workers[] = $worker;
        }

        $deadline = microtime(true) + 5;

        do {
            $waiting = DB::connection('checkout_observer')->select(
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
            'orders' => Order::query()->count(), 'redemptions' => PromotionRedemption::query()->count(),
            'stock' => Product::query()->orderBy('id')->pluck('stock_quantity')->all(),
        ];
        fwrite(STDOUT, "\nCheckout concurrency evidence: ".json_encode($evidence, JSON_THROW_ON_ERROR)."\n");

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

        DB::purge('checkout_observer');
    }
}

it('prevents overselling when stock five faces overlapping purchases of four and three', function () {
    $product = Product::factory()->create(['stock_quantity' => 5]);
    $first = CartItem::factory()->for($product)->create(['quantity' => 4]);
    $second = CartItem::factory()->for($product)->create(['quantity' => 3]);

    $results = simultaneousCheckoutRequests([$first->cart, $second->cart], $product);

    expect(array_column($results, 'status'))->toContain(201, 409);
    $winner = $results[0]['status'] === 201 ? $first : $second;
    $loser = $results[0]['status'] === 409 ? $first : $second;
    $failure = $results[0]['status'] === 409 ? $results[0] : $results[1];
    expect($failure['body']['error']['code'])->toBe('INSUFFICIENT_STOCK');
    expect($product->fresh()->stock_quantity)->toBe(5 - $winner->quantity)->toBeGreaterThanOrEqual(0);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 1);
    $this->assertDatabaseCount('promotion_redemptions', 0);
    $this->assertModelExists($loser);
    expect($loser->fresh()->quantity)->toBe($loser->quantity);
    expect($winner->cart->fresh()->items)->toBeEmpty();
    $this->assertDatabaseMissing('orders', ['user_id' => $loser->cart->user_id]);
});

it('serializes the last promotion use after product locking and completely rolls back the losing checkout', function () {
    $promotion = Promotion::factory()->limited(1, 1)->create();
    $first = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 5]))->create(['quantity' => 2]);
    $second = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 5]))->create(['quantity' => 3]);
    $first->cart->promotion()->associate($promotion)->save();
    $second->cart->promotion()->associate($promotion)->save();

    $results = simultaneousCheckoutRequests([$first->cart, $second->cart], $promotion);

    expect(array_column($results, 'status'))->toContain(201, 409);
    $winner = $results[0]['status'] === 201 ? $first : $second;
    $loser = $results[0]['status'] === 409 ? $first : $second;
    $failure = $results[0]['status'] === 409 ? $results[0] : $results[1];
    expect($failure['body']['error']['code'])->toBe('PROMOTION_GLOBAL_USAGE_LIMIT_REACHED');
    expect($winner->product->fresh()->stock_quantity)->toBe(5 - $winner->quantity);
    expect($loser->product->fresh()->stock_quantity)->toBe(5);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 1);
    $this->assertDatabaseCount('promotion_redemptions', 1);
    expect(PromotionRedemption::query()->sole()->order_id)->toBe(Order::query()->sole()->id);
    expect($loser->fresh()->quantity)->toBe($loser->quantity);
    expect($loser->cart->fresh()->promotion_id)->toBe($promotion->id);
    $this->assertDatabaseMissing('orders', ['user_id' => $loser->cart->user_id]);
});

it('creates exactly one order stock deduction and redemption for concurrent identical customer keys', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 5]))->create(['quantity' => 2]);
    $promotion = Promotion::factory()->limited(1, 1)->create();
    $item->cart->promotion()->associate($promotion)->save();

    $results = simultaneousCheckoutRequests([$item->cart, $item->cart], $item->cart, 'simultaneous-replay');

    expect(array_column($results, 'status'))->toContain(201, 200);
    expect($results[0]['body'])->toBe($results[1]['body']);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 1);
    $this->assertDatabaseCount('promotion_redemptions', 1);
    expect($item->product->fresh()->stock_quantity)->toBe(3);
    expect($item->cart->fresh()->items)->toBeEmpty();
    expect($item->cart->fresh()->promotion_id)->toBeNull();
});

it('locks overlapping product sets by ascending ID despite opposite cart insertion order', function () {
    $firstProduct = Product::factory()->create(['stock_quantity' => 10]);
    $secondProduct = Product::factory()->create(['stock_quantity' => 10]);
    $first = CartItem::factory()->for($secondProduct)->create(['quantity' => 2]);
    CartItem::factory()->for($first->cart)->for($firstProduct)->create(['quantity' => 3]);
    $second = CartItem::factory()->for($firstProduct)->create(['quantity' => 2]);
    CartItem::factory()->for($second->cart)->for($secondProduct)->create(['quantity' => 3]);

    $results = simultaneousCheckoutRequests([$first->cart, $second->cart], $firstProduct);

    expect(array_column($results, 'status'))->toBe([201, 201]);
    foreach ($results as $result) {
        expect($result['locks'])->toHaveCount(2);
        expect($result['locks'][0])->toContain('"carts"');
        expect($result['locks'][1])->toContain('"products"', 'order by "id" asc');
    }
    expect($firstProduct->fresh()->stock_quantity)->toBe(5);
    expect($secondProduct->fresh()->stock_quantity)->toBe(5);
    $this->assertDatabaseCount('orders', 2);
    $this->assertDatabaseCount('order_items', 4);
    $this->assertDatabaseCount('cart_items', 0);
});

it('retries complete checkout after two injected PostgreSQL deadlock errors without duplicate writes', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 5]))->create(['quantity' => 2]);
    $promotion = Promotion::factory()->limited(1, 1)->create();
    $item->cart->promotion()->associate($promotion)->save();
    $attempts = 0;
    $keys = [];
    Event::listen('eloquent.created: '.PromotionRedemption::class, function (PromotionRedemption $redemption) use (&$attempts, &$keys): void {
        $attempts++;
        $keys[] = $redemption->redemption_key;

        if ($attempts <= 2) {
            DB::statement("DO 'BEGIN RAISE EXCEPTION ''deadlock detected (injected)'' USING ERRCODE = ''40P01''; END'");
        }
    });

    $response = $this->withToken($item->cart->user->createToken('deadlock-retry')->plainTextToken)
        ->postJson('/api/checkout', [], ['Idempotency-Key' => 'deadlock-retry']);

    expect($attempts)->toBe(3);
    expect(array_unique($keys))->toHaveCount(1);
    expect(DB::transactionLevel())->toBe(0);
    $response->assertCreated();
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 1);
    $this->assertDatabaseCount('promotion_redemptions', 1);
    expect($item->product->fresh()->stock_quantity)->toBe(3);
    expect($item->cart->fresh()->items)->toBeEmpty();
    expect($item->cart->fresh()->promotion_id)->toBeNull();
});

it('returns 409 after three injected PostgreSQL deadlock errors with every attempt fully rolled back', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 5]))->create(['quantity' => 2]);
    $promotion = Promotion::factory()->limited(1, 1)->create();
    $item->cart->promotion()->associate($promotion)->save();
    $attempts = 0;
    $keys = [];
    Event::listen('eloquent.created: '.PromotionRedemption::class, function (PromotionRedemption $redemption) use (&$attempts, &$keys): void {
        $attempts++;
        $keys[] = $redemption->redemption_key;

        if ($attempts <= 3) {
            DB::statement("DO 'BEGIN RAISE EXCEPTION ''deadlock detected (injected)'' USING ERRCODE = ''40P01''; END'");
        }
    });

    $response = $this->withToken($item->cart->user->createToken('deadlock-retry')->plainTextToken)
        ->postJson('/api/checkout', [], ['Idempotency-Key' => 'deadlock-retry']);

    expect($attempts)->toBe(3);
    expect(array_unique($keys))->toHaveCount(1);
    expect(DB::transactionLevel())->toBe(0);
    $response->assertConflict()->assertJsonPath('error.code', 'CHECKOUT_CONFLICT');
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
    $this->assertDatabaseCount('promotion_redemptions', 0);
    expect($item->product->fresh()->stock_quantity)->toBe(5);
    $this->assertModelExists($item);
    expect($item->cart->fresh()->promotion_id)->toBe($promotion->id);
});
