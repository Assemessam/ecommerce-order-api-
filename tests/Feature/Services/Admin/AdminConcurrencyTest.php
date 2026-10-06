<?php

use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;
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
 * Both independent HTTP workers must be observed waiting on the row barrier before release.
 * The first worker is observed waiting before the second is launched to exercise both queue orders.
 *
 * @param  array<int, array{user: User, method: string, path: string, body: array<string, mixed>}>  $requests
 * @return array<int, array{status: int, body: array<string, mixed>, pid: int, database: string, locks: array<string>}>
 */
function simultaneousAdminRequests(array $requests, Model $barrier, int $firstIndex = 0): array
{
    expect(DB::transactionLevel())->toBe(0);
    expect(DB::selectOne('SHOW transaction_isolation')->transaction_isolation)->toBe('read committed');
    $connectionConfig = config('database.connections.pgsql');
    config(['database.connections.admin_observer' => $connectionConfig]);
    $environment = [
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
        'DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'postgres', 'DB_PORT' => '5432',
        'DB_DATABASE' => 'ecommerce_order_api_test', 'DB_USERNAME' => $connectionConfig['username'],
        'DB_PASSWORD' => $connectionConfig['password'], 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        'RATE_LIMIT_REDIS_DB' => '7', 'RATE_LIMIT_REDIS_URL' => '',
        'RATE_LIMIT_REDIS_PREFIX' => config('database.redis.rate-limits.prefix'),
    ];
    $run = 'admin-concurrency-'.bin2hex(random_bytes(8));
    $names = [$run.'-1', $run.'-2'];
    $workers = [];
    $waiting = [];
    $tokens = array_map(fn (array $request): string => $request['user']->createToken('admin-concurrency')->plainTextToken, $requests);
    DB::beginTransaction();

    try {
        $barrier->newQuery()->lockForUpdate()->findOrFail($barrier->getKey());

        foreach ([$firstIndex, 1 - $firstIndex] as $index) {
            $request = $requests[$index];
            $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/cart-promotion-request.php')], base_path(), $environment);
            $worker->setInput(json_encode([
                'application_name' => $names[$index], 'token' => $tokens[$index],
                'method' => $request['method'], 'path' => $request['path'], 'body' => $request['body'],
            ], JSON_THROW_ON_ERROR));
            $worker->setTimeout(15);
            $worker->start();
            $workers[$index] = $worker;
            $deadline = microtime(true) + 5;

            do {
                $waiting = DB::connection('admin_observer')->select(
                    'SELECT pid, query FROM pg_stat_activity WHERE application_name IN (?, ?) AND wait_event_type = ? AND state = ?',
                    [$names[0], $names[1], 'Lock', 'active'],
                );

                if (count($waiting) === count($workers)) {
                    break;
                }

                usleep(10000);
            } while (microtime(true) < $deadline && $worker->isRunning());

            expect($waiting)->toHaveCount(count($workers));
        }

        expect(array_unique(array_column($waiting, 'pid')))->toHaveCount(2);

        foreach ($waiting as $waiter) {
            expect($waiter->query)->toContain('"'.$barrier->getTable().'"', 'for update');
        }

        DB::rollBack();
        $results = [];
        ksort($workers);

        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }

        expect($results[0]['pid'])->not->toBe($results[1]['pid']);
        expect(array_column($results, 'database'))->toBe(['ecommerce_order_api_test', 'ecommerce_order_api_test']);
        fwrite(STDOUT, "\nAdmin concurrency evidence: ".json_encode([
            'paths' => array_column($requests, 'path'), 'first_worker' => $firstIndex,
            'barrier' => $barrier->getTable(), 'waiting_pids' => array_column($waiting, 'pid'),
            'worker_pids' => array_column($results, 'pid'), 'statuses' => array_column($results, 'status'),
            'errors' => array_map(fn (array $result): ?string => $result['body']['error']['code'] ?? null, $results),
            'orders' => Order::query()->count(), 'redemptions' => PromotionRedemption::query()->count(),
            'stock' => Product::query()->orderBy('id')->pluck('stock_quantity')->all(),
            'global_limits' => Promotion::query()->orderBy('id')->pluck('global_usage_limit')->all(),
            'customer_limits' => Promotion::query()->orderBy('id')->pluck('per_customer_usage_limit')->all(),
        ], JSON_THROW_ON_ERROR)."\n");

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

        DB::purge('admin_observer');
    }
}

it('preserves both an admin stock addition and checkout deduction under actual contention', function (int $firstIndex) {
    $product = Product::factory()->create(['stock_quantity' => 5]);
    $item = CartItem::factory()->for($product)->create(['quantity' => 4]);
    $administrator = User::factory()->administrator()->create();

    $results = simultaneousAdminRequests([
        ['user' => $administrator, 'method' => 'PATCH', 'path' => '/api/admin/products/'.$product->id, 'body' => ['stock_adjustment' => 3]],
        ['user' => $item->cart->user, 'method' => 'POST', 'path' => '/api/checkout', 'body' => []],
    ], $product, $firstIndex);

    expect(array_column($results, 'status'))->toBe([200, 201]);
    expect($results[0]['locks'])->toHaveCount(1);
    expect($results[0]['locks'][0])->toContain('"products"');
    expect($results[1]['locks'][0])->toContain('"carts"');
    expect($results[1]['locks'][1])->toContain('"products"', 'order by "id" asc');
    expect($product->fresh()->stock_quantity)->toBe(4);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('cart_items', 0);
})->with(['admin queued first' => 0, 'checkout queued first' => 1]);

it('rejects the losing stock removal or checkout without a negative quantity or partial purchase', function (int $firstIndex) {
    $product = Product::factory()->create(['stock_quantity' => 5]);
    $item = CartItem::factory()->for($product)->create(['quantity' => 4]);

    $results = simultaneousAdminRequests([
        ['user' => User::factory()->administrator()->create(), 'method' => 'PATCH', 'path' => '/api/admin/products/'.$product->id, 'body' => ['stock_adjustment' => -3]],
        ['user' => $item->cart->user, 'method' => 'POST', 'path' => '/api/checkout', 'body' => []],
    ], $product, $firstIndex);

    if ($results[0]['status'] === 200) {
        expect($results[1]['status'])->toBe(409);
        expect($results[1]['body']['error']['code'])->toBe('INSUFFICIENT_STOCK');
        expect($product->fresh()->stock_quantity)->toBe(2);
        $this->assertDatabaseCount('orders', 0);
        $this->assertModelExists($item);
    } else {
        expect($results[0]['status'])->toBe(409);
        expect($results[0]['body']['error']['code'])->toBe('INVENTORY_ADJUSTMENT_CONFLICT');
        expect($results[1]['status'])->toBe(201);
        expect($product->fresh()->stock_quantity)->toBe(1);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('cart_items', 0);
    }
})->with(['admin queued first' => 0, 'checkout queued first' => 1]);

it('preserves both a stock adjustment and order cancellation restoration under contention', function (int $firstIndex) {
    $product = Product::factory()->create(['stock_quantity' => 5]);
    $item = CartItem::factory()->for($product)->create(['quantity' => 4]);
    $this->withToken($item->cart->user->createToken('purchase')->plainTextToken)->postJson('/api/checkout')->assertCreated();
    $order = Order::query()->sole();

    $results = simultaneousAdminRequests([
        ['user' => User::factory()->administrator()->create(), 'method' => 'PATCH', 'path' => '/api/admin/products/'.$product->id, 'body' => ['stock_adjustment' => 3]],
        ['user' => $item->cart->user, 'method' => 'POST', 'path' => '/api/orders/'.$order->id.'/cancel', 'body' => []],
    ], $product, $firstIndex);

    expect(array_column($results, 'status'))->toBe([200, 200]);
    expect($product->fresh()->stock_quantity)->toBe(8);
    expect($order->fresh()->status->value)->toBe('cancelled');
    $this->assertDatabaseCount('orders', 1);
})->with(['admin queued first' => 0, 'cancellation queued first' => 1]);

it('serializes usage-limit reductions with checkout and preserves prior redemptions', function (string $field, int $firstIndex) {
    $isGlobal = $field === 'global_usage_limit';
    $promotion = Promotion::factory()->create([
        'global_usage_limit' => $isGlobal ? 2 : null,
        'per_customer_usage_limit' => $isGlobal ? null : 3,
    ]);
    $item = CartItem::factory()->for(Product::factory()->create(['price_minor' => 10000, 'stock_quantity' => 5]))->create();
    $item->cart->promotion()->associate($promotion)->save();
    $prior = PromotionRedemption::factory()->for($promotion)->for($item->cart->user)->count($isGlobal ? 1 : 2)->create();
    $prior->each->refresh();
    $records = $prior->map->getAttributes()->all();
    $target = $isGlobal ? 1 : 2;

    $results = simultaneousAdminRequests([
        ['user' => User::factory()->administrator()->create(), 'method' => 'PATCH', 'path' => '/api/admin/promotions/'.$promotion->id, 'body' => [$field => $target]],
        ['user' => $item->cart->user, 'method' => 'POST', 'path' => '/api/checkout', 'body' => []],
    ], $promotion, $firstIndex);

    expect($results[0]['locks'])->toHaveCount(1);
    expect($results[0]['locks'][0])->toContain('"promotions"');
    expect($results[1]['locks'][0])->toContain('"carts"');
    expect($results[1]['locks'][1])->toContain('"products"');
    expect($results[1]['locks'][2])->toContain('"promotions"');
    expect(PromotionRedemption::query()->whereKey($prior->modelKeys())->orderBy('id')->get()->map->getAttributes()->all())->toBe($records);

    if ($results[0]['status'] === 200) {
        expect($results[1]['status'])->toBe(409);
        expect($results[1]['body']['error']['code'])->toBe($isGlobal ? 'PROMOTION_GLOBAL_USAGE_LIMIT_REACHED' : 'PROMOTION_CUSTOMER_USAGE_LIMIT_REACHED');
        expect($promotion->fresh()->$field)->toBe($target);
        $this->assertDatabaseCount('promotion_redemptions', $target);
        $this->assertDatabaseCount('orders', 0);
        $this->assertModelExists($item);
        expect($item->product->fresh()->stock_quantity)->toBe(5);
    } else {
        expect($results[0]['status'])->toBe(409);
        expect($results[0]['body']['error']['code'])->toBe('PROMOTION_USAGE_LIMIT_CONFLICT');
        expect($results[1]['status'])->toBe(201);
        expect($promotion->fresh()->$field)->toBe($target + 1);
        $this->assertDatabaseCount('promotion_redemptions', $target + 1);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('cart_items', 0);
        expect($item->product->fresh()->stock_quantity)->toBe(4);
    }
})->with(['global_usage_limit', 'per_customer_usage_limit'])->with(['admin queued first' => 0, 'checkout queued first' => 1]);

it('snapshots either the complete old or complete new discount during concurrent promotion edits', function (int $firstIndex) {
    $promotion = Promotion::factory()->create(['value' => 2000]);
    $item = CartItem::factory()->for(Product::factory()->create(['price_minor' => 10000]))->create();
    $item->cart->promotion()->associate($promotion)->save();

    $results = simultaneousAdminRequests([
        ['user' => User::factory()->administrator()->create(), 'method' => 'PATCH', 'path' => '/api/admin/promotions/'.$promotion->id, 'body' => ['type' => 'fixed', 'value' => 500]],
        ['user' => $item->cart->user, 'method' => 'POST', 'path' => '/api/checkout', 'body' => []],
    ], $promotion, $firstIndex);

    expect(array_column($results, 'status'))->toBe([200, 201]);
    $order = Order::query()->sole();
    expect([$order->promotion_type_snapshot->value, $order->promotion_value_snapshot, $order->discount_minor])
        ->toBeIn([['percentage', 2000, 2000], ['fixed', 500, 500]]);
    expect(PromotionRedemption::query()->sole()->discount_minor)->toBe($order->discount_minor);
    expect($promotion->fresh()->type->value)->toBe('fixed');
    expect($promotion->fresh()->value)->toBe(500);
})->with(['admin queued first' => 0, 'checkout queued first' => 1]);

it('retains both administrators distinct partial product edits under contention', function () {
    $product = Product::factory()->create(['name' => 'Original', 'price_minor' => 100]);
    $administrator = User::factory()->administrator()->create();

    $results = simultaneousAdminRequests([
        ['user' => $administrator, 'method' => 'PATCH', 'path' => '/api/admin/products/'.$product->id, 'body' => ['name' => 'Updated']],
        ['user' => $administrator, 'method' => 'PATCH', 'path' => '/api/admin/products/'.$product->id, 'body' => ['price_minor' => 200]],
    ], $product);

    expect(array_column($results, 'status'))->toBe([200, 200]);
    expect($product->fresh()->name)->toBe('Updated');
    expect($product->fresh()->price_minor)->toBe(200);
});
it('returns 409 for exhausted transaction retries without leaking SQL or retaining partial updates', function () {
    $product = Product::factory()->create(['stock_quantity' => 5]);
    $attempts = 0;
    Event::listen('eloquent.updated: '.Product::class, function () use (&$attempts): void {
        $attempts++;
        DB::statement("DO 'BEGIN RAISE EXCEPTION ''deadlock detected (injected)'' USING ERRCODE = ''40P01''; END'");
    });

    $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
        ->patchJson('/api/admin/products/'.$product->id, ['stock_adjustment' => 2])
        ->assertConflict()->assertJsonPath('error.code', 'ADMINISTRATION_CONFLICT');

    expect($attempts)->toBe(3);
    expect($product->fresh()->stock_quantity)->toBe(5);
});

it('retries product stock adjustment without applying the delta more than once', function () {
    $product = Product::factory()->create(['stock_quantity' => 5]);
    $attempts = 0;
    Event::listen('eloquent.updated: '.Product::class, function () use (&$attempts): void {
        $attempts++;

        if ($attempts <= 2) {
            DB::statement("DO 'BEGIN RAISE EXCEPTION ''deadlock detected (injected)'' USING ERRCODE = ''40P01''; END'");
        }
    });

    $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
        ->patchJson('/api/admin/products/'.$product->id, ['stock_adjustment' => 2])
        ->assertOk()->assertJsonPath('data.stock_quantity', 7);

    expect($attempts)->toBe(3);
    expect($product->fresh()->stock_quantity)->toBe(7);
});

it('rolls back promotion edits and retains usage after exhausted transaction retries', function () {
    $promotion = Promotion::factory()->create();
    PromotionRedemption::factory()->for($promotion)->create();
    $attempts = 0;
    Event::listen('eloquent.updated: '.Promotion::class, function () use (&$attempts): void {
        $attempts++;
        DB::statement("DO 'BEGIN RAISE EXCEPTION ''deadlock detected (injected)'' USING ERRCODE = ''40P01''; END'");
    });

    $this->withToken(User::factory()->administrator()->create()->createToken('admin')->plainTextToken)
        ->patchJson('/api/admin/promotions/'.$promotion->id, ['is_active' => false])
        ->assertConflict()->assertJsonPath('error.code', 'ADMINISTRATION_CONFLICT');

    expect($attempts)->toBe(3);
    expect($promotion->fresh()->is_active)->toBeTrue();
    $this->assertDatabaseCount('promotion_redemptions', 1);
});
