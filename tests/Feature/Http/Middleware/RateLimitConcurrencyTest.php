<?php

use App\Models\CartItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

/**
 * Hold every independent request after its admission decision, before business work.
 * A check-then-hit implementation would admit every process through this barrier.
 *
 * @param  list<array{method: string, path: string, config: array<string, mixed>, token?: string, body?: array<string, mixed>, headers?: array<string, string>}>  $requests
 * @return list<array{status: int, remaining: string, body: array<string, mixed>}>
 */
function simultaneousRateLimitRequests(array $requests): array
{
    $workers = [];
    $streams = [];
    $admissions = [];
    try {
        foreach ($requests as $request) {
            $stream = new InputStream;
            $stream->write(json_encode($request, JSON_THROW_ON_ERROR).PHP_EOL);
            $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/rate-limit-request.php')], base_path(), [
                'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'postgres',
                'DB_PORT' => '5432', 'DB_DATABASE' => 'ecommerce_order_api_test', 'DB_URL' => '',
                'DB_USERNAME' => config('database.connections.pgsql.username'), 'DB_PASSWORD' => config('database.connections.pgsql.password'),
                'CACHE_STORE' => 'array', 'CATALOGUE_CACHE_ENABLED' => 'false', 'REDIS_HOST' => 'redis',
                'RATE_LIMIT_REDIS_DB' => '7', 'RATE_LIMIT_REDIS_URL' => '', 'RATE_LIMIT_REDIS_PREFIX' => config('database.redis.rate-limits.prefix'),
            ], $stream, 20);
            $worker->start();
            $streams[] = $stream;
            $workers[] = $worker;
        }
        foreach ($workers as $worker) {
            $deadline = microtime(true) + 10;
            do {
                if (str_contains($worker->getOutput(), "\n")) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline && $worker->isRunning());
            $admission = json_decode(trim($worker->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            expect($admission['admission'])->toBeTrue();
            $admissions[] = $admission;
        }
        foreach ($streams as $stream) {
            $stream->write("release\n");
            $stream->close();
        }
        $results = [];
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            $lines = explode("\n", trim($worker->getOutput()));
            $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
        }
        expect(array_unique(array_column($admissions, 'pid')))->toHaveCount(count($requests));
        expect(array_unique(array_column($admissions, 'redis_client_id')))->toHaveCount(count($requests));
        fwrite(STDOUT, "\nRate limit concurrency: ".json_encode(['statuses' => array_column($results, 'status'), 'connections' => array_column($admissions, 'redis_client_id')], JSON_THROW_ON_ERROR)."\n");
        foreach ($results as $result) {
            expect((int) $result['remaining'])->toBeGreaterThanOrEqual(0);
            if ($result['status'] === 429) {
                expect($result['body']['error']['code'])->toBe('TOO_MANY_REQUESTS');
                expect($result['remaining'])->toBe('0');
            }
        }

        return $results;
    } finally {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }
}

it('admits exactly three concurrent public requests through independent Redis connections', function () {
    $results = simultaneousRateLimitRequests(array_fill(0, 8, ['method' => 'GET', 'path' => '/api/products',
        'config' => ['rate-limits.policies.catalogue-read.attempts' => 3]]));
    $counts = array_count_values(array_column($results, 'status'));
    ksort($counts);
    expect($counts)->toBe([200 => 3, 429 => 5]);
});

it('shares counters across processes while giving each authenticated customer a separate allowance', function () {
    $customers = User::factory()->count(2)->create();
    $requests = [];
    foreach ($customers as $customer) {
        $token = $customer->createToken('concurrent')->plainTextToken;
        for ($index = 0; $index < 4; $index++) {
            $requests[] = ['method' => 'GET', 'path' => '/api/cart', 'token' => $token,
                'config' => ['rate-limits.policies.cart-read.attempts' => 2]];
        }
    }
    $results = simultaneousRateLimitRequests($requests);
    foreach (array_chunk($results, 4) as $customerResults) {
        $counts = array_count_values(array_column($customerResults, 'status'));
        ksort($counts);
        expect($counts)->toBe([200 => 2, 429 => 2]);
    }
});

it('preserves one idempotent checkout and one outbox event during concurrent admissions and denials', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 10]))->create(['quantity' => 2]);
    $promotion = Promotion::factory()->limited()->create();
    $item->cart->promotion()->associate($promotion)->save();
    $token = $item->cart->user->createToken('concurrent-checkout')->plainTextToken;
    $results = simultaneousRateLimitRequests(array_fill(0, 8, ['method' => 'POST', 'path' => '/api/checkout', 'token' => $token,
        'headers' => ['Idempotency-Key' => 'concurrent-admission'], 'config' => ['rate-limits.policies.checkout.attempts' => 2]]));
    $counts = array_count_values(array_column($results, 'status'));
    ksort($counts);
    expect($counts)->toBe([200 => 1, 201 => 1, 429 => 6]);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('promotion_redemptions', 1);
    $this->assertDatabaseCount('order_outbox_events', 1);
    $this->assertDatabaseCount('order_notifications', 0);
    $this->assertDatabaseCount('cart_items', 0);
    expect($item->product->fresh()->stock_quantity)->toBe(8);
});

it('cleans only its limiter namespace while preserving unrelated limiter catalogue and queue keys', function () {
    $sentinel = 'unrelated-limiter-test-'.Str::uuid();
    $own = Redis::connection('rate-limits');
    $connections = [Redis::connection('catalogue'), Redis::connection('order-events')];
    $unrelated = $own->client();
    $unrelated->rawCommand('SET', $sentinel, 'preserve', 'EX', '60');
    foreach ($connections as $connection) {
        $connection->setex($sentinel, 60, 'preserve');
    }
    try {
        $this->getJson('/api/products')->assertOk();
        expect($own->keys('*'))->not->toBeEmpty();
        $this->clearRateLimitKeys();
        expect($own->keys('*'))->toBe([]);
        expect($unrelated->rawCommand('GET', $sentinel))->toBe('preserve');
        foreach ($connections as $connection) {
            expect($connection->get($sentinel))->toBe('preserve');
        }
    } finally {
        $unrelated->rawCommand('DEL', $sentinel);
        foreach ($connections as $connection) {
            $connection->del($sentinel);
        }
    }
});
