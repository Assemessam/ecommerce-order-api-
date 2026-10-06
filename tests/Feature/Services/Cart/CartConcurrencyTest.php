<?php

use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

/**
 * Hold a product lock until both independent HTTP workers are visibly waiting on PostgreSQL locks.
 * Setup is committed: DatabaseMigrations intentionally replaces transaction-wrapped refresh tests.
 *
 * @return array<int, array{status: int, body: array<string, mixed>, pid: int, database: string}>
 */
function simultaneousCartAdds(User $user, Product $product, int $quantity): array
{
    expect(DB::transactionLevel())->toBe(0);
    expect(DB::selectOne('SHOW transaction_isolation')->transaction_isolation)->toBe('read committed');
    $token = $user->createToken('cart-concurrency')->plainTextToken;
    $connectionConfig = config('database.connections.pgsql');
    config(['database.connections.cart_observer' => $connectionConfig]);
    $environment = [
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
        'DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'postgres', 'DB_PORT' => '5432',
        'DB_DATABASE' => 'ecommerce_order_api_test', 'DB_USERNAME' => $connectionConfig['username'],
        'DB_PASSWORD' => $connectionConfig['password'], 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        'RATE_LIMIT_REDIS_DB' => '7', 'RATE_LIMIT_REDIS_URL' => '',
        'RATE_LIMIT_REDIS_PREFIX' => config('database.redis.rate-limits.prefix'),
    ];
    $run = 'cart-concurrency-'.bin2hex(random_bytes(8));
    $names = [$run.'-1', $run.'-2'];
    $workers = [];
    $waiting = [];
    DB::beginTransaction();

    try {
        Product::query()->lockForUpdate()->findOrFail($product->id);

        foreach ($names as $name) {
            $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/cart-request.php')], base_path(), $environment);
            $worker->setInput(json_encode([
                'application_name' => $name, 'token' => $token, 'product_id' => $product->id, 'quantity' => $quantity,
            ], JSON_THROW_ON_ERROR));
            $worker->setTimeout(15);
            $worker->start();
            $workers[] = $worker;
        }

        $deadline = microtime(true) + 5;

        do {
            $waiting = DB::connection('cart_observer')->select(
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
        $queries = implode(' ', array_column($waiting, 'query'));
        expect($queries)->toContain('products', 'for update', 'carts');
        DB::rollBack();
        $results = [];

        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }

        expect($results[0]['pid'])->not->toBe($results[1]['pid']);
        expect(array_column($results, 'database'))->toBe(['ecommerce_order_api_test', 'ecommerce_order_api_test']);

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

        DB::purge('cart_observer');
    }
}

it('merges concurrent first-time HTTP additions into one cart and one line', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['stock_quantity' => 10, 'price_minor' => 100]);

    $results = simultaneousCartAdds($user, $product, 3);

    expect(array_column($results, 'status'))->toBe([201, 201]);
    $quantities = array_map(fn (array $result): int => $result['body']['data']['items'][0]['quantity'], $results);
    sort($quantities);
    expect($quantities)->toBe([3, 6]);
    $this->assertDatabaseCount('carts', 1);
    $this->assertDatabaseCount('cart_items', 1);
    $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => 6]);
    expect($product->fresh()->stock_quantity)->toBe(10);
});

it('preserves both concurrent additions to an existing line without losing an update', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 10, 'price_minor' => 100]))->create(['quantity' => 1]);

    $results = simultaneousCartAdds($item->cart->user, $item->product, 3);

    expect(array_column($results, 'status'))->toBe([201, 201]);
    $quantities = array_map(fn (array $result): int => $result['body']['data']['items'][0]['quantity'], $results);
    sort($quantities);
    expect($quantities)->toBe([4, 7]);
    $this->assertDatabaseCount('carts', 1);
    $this->assertDatabaseCount('cart_items', 1);
    expect($item->fresh()->quantity)->toBe(7);
    expect($item->product->fresh()->stock_quantity)->toBe(10);
});

it('rejects the concurrent addition exceeding accumulated stock without a partial write', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['stock_quantity' => 5]);

    $results = simultaneousCartAdds($user, $product, 3);

    $statuses = array_column($results, 'status');
    sort($statuses);
    expect($statuses)->toBe([201, 409]);
    $failure = collect($results)->firstWhere('status', 409);
    expect($failure['body']['error']['code'])->toBe('INSUFFICIENT_STOCK');
    $this->assertDatabaseCount('carts', 1);
    $this->assertDatabaseCount('cart_items', 1);
    $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => 3]);
    expect($product->fresh()->stock_quantity)->toBe(5);
});
