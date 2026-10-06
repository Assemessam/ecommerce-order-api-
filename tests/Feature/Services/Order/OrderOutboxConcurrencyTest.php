<?php

use App\Enums\OrderOutboxStatus;
use App\Models\CartItem;
use App\Models\OrderOutboxEvent;
use App\Models\Product;
use App\Services\Checkout\CheckoutService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

afterEach(function (): void {
    DB::statement('TRUNCATE orders CASCADE');
});

/** @return array<string, string> */
function outboxConcurrencyEnvironment(): array
{
    return ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'postgres',
        'DB_PORT' => '5432', 'DB_DATABASE' => 'ecommerce_order_api_test', 'DB_URL' => '',
        'DB_USERNAME' => config('database.connections.pgsql.username'), 'DB_PASSWORD' => config('database.connections.pgsql.password'),
        'CACHE_STORE' => 'array', 'CATALOGUE_CACHE_ENABLED' => 'false', 'ORDER_EVENTS_REDIS_DB' => '5', 'ORDER_EVENTS_REDIS_URL' => ''];
}

/** @return array{pid: int, ids: list<string>} */
function awaitOutboxClaim(Process $worker): array
{
    $deadline = microtime(true) + 5;
    do {
        $output = $worker->getOutput();
        if (str_contains($output, "\n")) {
            return json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
        }
        usleep(10000);
    } while (microtime(true) < $deadline && $worker->isRunning());
    throw new RuntimeException('Claim worker did not reach its held-transaction barrier: '.$worker->getErrorOutput());
}

it('uses independent transactions to claim disjoint batches while skipping locked rows', function () {
    $events = OrderOutboxEvent::factory()->count(5)->create();
    $barrier = $events->sortBy('available_at')->first();
    $workers = [];
    $inputs = [];
    $claims = [];
    $names = ['outbox-claim-'.Str::uuid(), 'outbox-claim-'.Str::uuid()];
    config(['database.connections.outbox_observer' => config('database.connections.pgsql')]);
    DB::beginTransaction();
    try {
        OrderOutboxEvent::query()->whereKey($barrier->id)->lockForUpdate()->firstOrFail();
        foreach ($names as $name) {
            $input = new InputStream;
            $input->write(json_encode(['action' => 'claim', 'name' => $name, 'limit' => 2], JSON_THROW_ON_ERROR).PHP_EOL);
            $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/order-event-worker.php')], base_path(), outboxConcurrencyEnvironment(), $input, 15);
            $worker->start();
            $inputs[] = $input;
            $workers[] = $worker;
            $claims[] = awaitOutboxClaim($worker);
        }
        expect($claims[0]['ids'])->toHaveCount(2);
        expect($claims[1]['ids'])->toHaveCount(2);
        expect($claims[0]['pid'])->not->toBe($claims[1]['pid']);
        expect(array_intersect($claims[0]['ids'], $claims[1]['ids']))->toBeEmpty();
        expect(array_merge($claims[0]['ids'], $claims[1]['ids']))->not->toContain($barrier->id);
        $held = DB::connection('outbox_observer')->select('SELECT pid, state FROM pg_stat_activity WHERE application_name IN (?, ?)', $names);
        expect($held)->toHaveCount(2);
        expect(array_column($held, 'state'))->toBe(['idle in transaction', 'idle in transaction']);
        foreach ($inputs as $input) {
            $input->write("commit\n");
            $input->close();
        }
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
        }
        DB::rollBack();
        expect(OrderOutboxEvent::query()->where('status', OrderOutboxStatus::Queued)->count())->toBe(4);
        expect($barrier->fresh()->status)->toBe(OrderOutboxStatus::Pending);
        expect(OrderOutboxEvent::query()->whereNotNull('dispatch_token')->distinct()->count('dispatch_token'))->toBe(4);
        fwrite(STDOUT, "\nOutbox claim evidence: ".json_encode($claims, JSON_THROW_ON_ERROR)."\n");
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        DB::purge('outbox_observer');
    }
});

it('creates one durable effect when two observed independent processors race on the same event', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 10]))->create(['quantity' => 2]);
    $order = app(CheckoutService::class)->checkout($item->cart->user)->order;
    $event = OrderOutboxEvent::query()->sole();
    $event->update(['status' => OrderOutboxStatus::Queued, 'dispatch_token' => (string) Str::uuid(),
        'dispatch_attempts' => 1, 'available_at' => now('UTC')->addMinutes(5)]);
    $workers = [];
    $names = ['outbox-process-'.Str::uuid(), 'outbox-process-'.Str::uuid()];
    config(['database.connections.outbox_observer' => config('database.connections.pgsql')]);
    DB::beginTransaction();
    try {
        OrderOutboxEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
        foreach ($names as $name) {
            $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/order-event-worker.php')], base_path(), outboxConcurrencyEnvironment(),
                json_encode(['action' => 'process', 'name' => $name, 'id' => $event->id, 'token' => $event->dispatch_token], JSON_THROW_ON_ERROR).PHP_EOL, 15);
            $worker->start();
            $workers[] = $worker;
        }
        $deadline = microtime(true) + 5;
        do {
            $waiting = DB::connection('outbox_observer')->select(
                'SELECT pid, query FROM pg_stat_activity WHERE application_name IN (?, ?) AND wait_event_type = ? AND state = ?',
                [$names[0], $names[1], 'Lock', 'active']);
            if (count($waiting) === 2) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline && $workers[0]->isRunning() && $workers[1]->isRunning());
        expect($waiting)->toHaveCount(2);
        expect(array_unique(array_column($waiting, 'pid')))->toHaveCount(2);
        foreach ($waiting as $waiter) {
            expect($waiter->query)->toContain('"order_outbox_events"', 'for update');
        }
        DB::rollBack();
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            expect($worker->getOutput())->toContain('"processed":true');
        }
        $this->assertDatabaseCount('order_notifications', 1);
        expect($event->fresh()->status)->toBe(OrderOutboxStatus::Processed);
        expect($event->fresh()->processing_attempts)->toBe(1);
        expect($item->product->fresh()->stock_quantity)->toBe(8);
        expect($order->fresh()->cancelled_at)->toBeNull();
        fwrite(STDOUT, "\nOutbox processing evidence: ".json_encode(['waiting_pids' => array_column($waiting, 'pid'), 'event_id' => $event->id, 'effects' => 1], JSON_THROW_ON_ERROR)."\n");
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        DB::purge('outbox_observer');
    }
});
