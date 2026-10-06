<?php

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

/**
 * Both HTTP workers must be observed waiting on the cart barrier before it is released.
 * All fixtures are committed and each worker uses a separate PostgreSQL connection.
 *
 * @param  array<int, array{method: string, path: string, body: array<string, mixed>}>  $requests
 * @return array<int, array{status: int, body: ?array<string, mixed>, pid: int, database: string}>
 */
function simultaneousCartPromotionRequests(Cart $cart, array $requests): array
{
    expect(DB::transactionLevel())->toBe(0);
    expect(DB::selectOne('SHOW transaction_isolation')->transaction_isolation)->toBe('read committed');
    $token = $cart->user->createToken('promotion-concurrency')->plainTextToken;
    $connectionConfig = config('database.connections.pgsql');
    config(['database.connections.promotion_observer' => $connectionConfig]);
    $environment = [
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
        'DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'postgres', 'DB_PORT' => '5432',
        'DB_DATABASE' => 'ecommerce_order_api_test', 'DB_USERNAME' => $connectionConfig['username'],
        'DB_PASSWORD' => $connectionConfig['password'], 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    ];
    $run = 'promotion-concurrency-'.bin2hex(random_bytes(8));
    $names = [$run.'-1', $run.'-2'];
    $workers = [];
    $waiting = [];
    DB::beginTransaction();

    try {
        Cart::query()->lockForUpdate()->findOrFail($cart->id);

        foreach ($requests as $index => $request) {
            $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/cart-promotion-request.php')], base_path(), $environment);
            $worker->setInput(json_encode($request + ['application_name' => $names[$index], 'token' => $token], JSON_THROW_ON_ERROR));
            $worker->setTimeout(15);
            $worker->start();
            $workers[] = $worker;
        }

        $deadline = microtime(true) + 5;

        do {
            $waiting = DB::connection('promotion_observer')->select(
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
            expect($waiter->query)->toContain('"carts"', 'for update');
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

        DB::purge('promotion_observer');
    }
}

it('serializes concurrent coupon replacements without stacking or consuming usage', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['price_minor' => 10000, 'stock_quantity' => 10]))->create();
    $first = Promotion::factory()->limited(1, 1)->create();
    $second = Promotion::factory()->fixed(500)->limited(1, 1)->create();

    $results = simultaneousCartPromotionRequests($item->cart, [
        ['method' => 'POST', 'path' => '/api/cart/promotion', 'body' => ['code' => $first->code]],
        ['method' => 'POST', 'path' => '/api/cart/promotion', 'body' => ['code' => $second->code]],
    ]);

    expect(array_column($results, 'status'))->toBe([200, 200]);
    expect($results[0]['body']['data']['promotion']['id'])->toBe($first->id);
    expect($results[1]['body']['data']['promotion']['id'])->toBe($second->id);
    expect($results[0]['body']['data']['estimated_discount']['amount_minor'])->toBe(2000);
    expect($results[1]['body']['data']['estimated_discount']['amount_minor'])->toBe(500);
    expect($item->cart->fresh()->promotion_id)->toBeIn([$first->id, $second->id]);
    expect($item->fresh()->quantity)->toBe(1);
    expect($item->product->fresh()->stock_quantity)->toBe(10);
    $this->assertDatabaseCount('promotion_redemptions', 0);
    $this->assertDatabaseCount('carts', 1);
});

it('serializes simultaneous application and removal with one coherent final selection', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['price_minor' => 10000, 'stock_quantity' => 10]))->create();
    $promotion = Promotion::factory()->create();

    $results = simultaneousCartPromotionRequests($item->cart, [
        ['method' => 'POST', 'path' => '/api/cart/promotion', 'body' => ['code' => $promotion->code]],
        ['method' => 'DELETE', 'path' => '/api/cart/promotion', 'body' => []],
    ]);

    expect(array_column($results, 'status'))->toBe([200, 204]);
    expect($results[0]['body']['data']['promotion']['id'])->toBe($promotion->id);
    expect($item->cart->fresh()->promotion_id)->toBeIn([null, $promotion->id]);
    $this->withToken($item->cart->user->createToken('final-read')->plainTextToken)->getJson('/api/cart')
        ->assertOk()->assertJsonPath('data.estimated_discount.amount_minor', $item->cart->fresh()->promotion_id === null ? 0 : 2000);
    expect($item->product->fresh()->stock_quantity)->toBe(10);
    $this->assertDatabaseCount('promotion_redemptions', 0);
    $this->assertDatabaseCount('cart_items', 1);
});

it('makes simultaneous removals idempotent and preserves the cart and inventory', function () {
    $item = CartItem::factory()->create();
    $stock = $item->product->stock_quantity;
    $promotion = Promotion::factory()->create();
    $item->cart->promotion()->associate($promotion)->save();

    $results = simultaneousCartPromotionRequests($item->cart, [
        ['method' => 'DELETE', 'path' => '/api/cart/promotion', 'body' => []],
        ['method' => 'DELETE', 'path' => '/api/cart/promotion', 'body' => []],
    ]);

    expect(array_column($results, 'status'))->toBe([204, 204]);
    expect($item->cart->fresh()->promotion_id)->toBeNull();
    expect($item->product->fresh()->stock_quantity)->toBe($stock);
    $this->assertDatabaseCount('cart_items', 1);
    $this->assertDatabaseCount('promotion_redemptions', 0);
});
