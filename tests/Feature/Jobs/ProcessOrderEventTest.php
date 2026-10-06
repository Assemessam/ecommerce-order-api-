<?php

use App\Contracts\Repositories\OrderOutboxRepositoryInterface;
use App\Enums\OrderEventType;
use App\Enums\OrderOutboxStatus;
use App\Jobs\ProcessOrderEvent;
use App\Models\CartItem;
use App\Models\OrderNotification;
use App\Models\OrderOutboxEvent;
use App\Models\Product;
use App\Models\Promotion;
use App\Services\Checkout\CheckoutService;
use App\Services\Order\OrderEventProcessingService;
use App\Services\Order\OrderOutboxService;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

uses(DatabaseMigrations::class);

/** @return array<string, string> */
function orderEventWorkerEnvironment(): array
{
    return ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'postgres',
        'DB_PORT' => '5432', 'DB_DATABASE' => 'ecommerce_order_api_test', 'DB_URL' => '',
        'DB_USERNAME' => config('database.connections.pgsql.username'), 'DB_PASSWORD' => config('database.connections.pgsql.password'),
        'CACHE_STORE' => 'array', 'CATALOGUE_CACHE_ENABLED' => 'false', 'REDIS_HOST' => 'redis',
        'ORDER_EVENTS_REDIS_DB' => '5', 'ORDER_EVENTS_REDIS_URL' => '', 'QUEUE_FAILED_DRIVER' => 'database-uuids'];
}

function runOrderQueueWorker(string $queue, bool $fail = false): void
{
    $process = new Process([PHP_BINARY, base_path('tests/Fixtures/order-event-worker.php')], base_path(), orderEventWorkerEnvironment(),
        json_encode(['action' => 'queue', 'queue' => $queue, 'fail' => $fail], JSON_THROW_ON_ERROR).PHP_EOL, 20);
    $process->mustRun();
    expect($process->getOutput())->toContain('"worker_exit":0');
}

beforeEach(function () {
    $this->orderQueue = 'order-events-test-'.Str::uuid();
    config(['order-events.queue' => $this->orderQueue, 'order-events.connection' => 'order-redis',
        'database.redis.order-events.database' => 5, 'database.redis.order-events.url' => null,
        'database.redis.order-events-outage' => array_replace(config('database.redis.order-events'), ['database' => 5, 'url' => null, 'port' => 6380]),
        'queue.connections.order-redis-outage' => array_replace(config('queue.connections.order-redis'), ['connection' => 'order-events-outage'])]);
    Redis::purge('order-events');
});

afterEach(function () {
    Redis::purge('order-events');
    config(['order-events.connection' => 'order-redis']);
    Queue::connection('order-redis')->clear($this->orderQueue);
    DB::statement('TRUNCATE orders CASCADE');
});

it('uses real Redis workers for placed and cancelled events without changing committed business state', function () {
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 10]))->create(['quantity' => 2]);
    $promotion = Promotion::factory()->create();
    $item->cart->promotion()->associate($promotion)->save();
    $order = app(CheckoutService::class)->checkout($item->cart->user, 'real-worker')->order;
    app(OrderService::class)->cancelOrder($item->cart->user, (string) $order->id);
    $snapshot = $order->fresh()->toArray();
    $this->assertDatabaseCount('order_notifications', 0);
    $result = app(OrderOutboxService::class)->dispatchDue(100);
    expect($result)->toBe(['claimed' => 2, 'dispatched' => 2, 'error' => null]);
    expect(Queue::connection('order-redis')->size($this->orderQueue))->toBe(2);
    runOrderQueueWorker($this->orderQueue);
    runOrderQueueWorker($this->orderQueue);
    $this->assertDatabaseCount('order_notifications', 2);
    expect(OrderNotification::query()->pluck('event_type')->all())->toEqualCanonicalizing([OrderEventType::OrderPlaced, OrderEventType::OrderCancelled]);
    foreach (OrderOutboxEvent::query()->get() as $event) {
        expect($event->status)->toBe(OrderOutboxStatus::Processed);
        expect($event->processing_attempts)->toBe(1);
        expect($event->processed_at)->not->toBeNull();
        $notification = OrderNotification::query()->where('event_id', $event->id)->sole();
        expect($notification->order_id)->toBe($order->id);
        expect($notification->occurred_at->equalTo($event->occurred_at))->toBeTrue();
    }
    expect($order->fresh()->toArray())->toBe($snapshot);
    expect($item->product->fresh()->stock_quantity)->toBe(10);
    $this->assertDatabaseCount('promotion_redemptions', 1);
    expect(app(OrderOutboxService::class)->dispatchDue(100)['claimed'])->toBe(0);
});

it('makes duplicate queued jobs and stale job failures harmless', function () {
    $event = OrderOutboxEvent::factory()->queued()->create();
    $job = new ProcessOrderEvent($event->id, $event->dispatch_token);
    Queue::connection('order-redis')->push($job, queue: $this->orderQueue);
    Queue::connection('order-redis')->push($job, queue: $this->orderQueue);
    runOrderQueueWorker($this->orderQueue);
    runOrderQueueWorker($this->orderQueue);
    $job->failed(new RuntimeException('Late failure'));
    $this->assertDatabaseCount('order_notifications', 1);
    expect($event->fresh()->status)->toBe(OrderOutboxStatus::Processed);
    expect($event->fresh()->processing_attempts)->toBe(1);
    expect($event->fresh()->failed_at)->toBeNull();
});

it('rolls back listener effects on a transient real worker failure and processes its retry once', function () {
    $event = OrderOutboxEvent::factory()->queued()->create();
    $job = new ProcessOrderEvent($event->id, $event->dispatch_token);
    $job->backoff = [0];
    Queue::connection('order-redis')->push($job, queue: $this->orderQueue);
    runOrderQueueWorker($this->orderQueue, fail: true);
    $this->assertDatabaseCount('order_notifications', 0);
    expect($event->fresh()->status)->toBe(OrderOutboxStatus::Queued);
    expect($event->fresh()->processing_attempts)->toBe(1);
    expect($event->fresh()->last_error)->toBe(RuntimeException::class);
    $this->assertDatabaseCount('failed_jobs', 0);
    runOrderQueueWorker($this->orderQueue);
    $this->assertDatabaseCount('order_notifications', 1);
    expect($event->fresh()->status)->toBe(OrderOutboxStatus::Processed);
    expect($event->fresh()->processing_attempts)->toBe(2);
    expect($event->fresh()->last_error)->toBeNull();
});

it('records exhausted native queue failures and safely recovers through an outbox retry', function () {
    $event = OrderOutboxEvent::factory()->queued()->create();
    $job = new ProcessOrderEvent($event->id, $event->dispatch_token);
    $job->backoff = [0];
    Queue::connection('order-redis')->push($job, queue: $this->orderQueue);
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        runOrderQueueWorker($this->orderQueue, fail: true);
        expect($event->fresh()->processing_attempts)->toBe($attempt);
        $this->assertDatabaseCount('order_notifications', 0);
        if ($attempt < 3) {
            expect($event->fresh()->status)->toBe(OrderOutboxStatus::Queued);
            $this->assertDatabaseCount('failed_jobs', 0);
        }
    }
    expect($event->fresh()->status)->toBe(OrderOutboxStatus::Failed);
    expect($event->fresh()->failed_at)->not->toBeNull();
    expect($event->fresh()->last_error)->toBe(RuntimeException::class);
    $this->assertDatabaseCount('failed_jobs', 1);
    $this->assertDatabaseHas('failed_jobs', ['connection' => 'order-redis', 'queue' => $this->orderQueue]);
    $this->assertDatabaseCount('order_notifications', 0);
    expect(app(OrderOutboxService::class)->retry($event->id))->toBeTrue();
    app(OrderOutboxService::class)->dispatchDue(10);
    $job->failed(new RuntimeException('Failure from invalidated token'));
    expect($event->fresh()->status)->toBe(OrderOutboxStatus::Queued);
    runOrderQueueWorker($this->orderQueue);
    expect($event->fresh()->status)->toBe(OrderOutboxStatus::Processed);
    $this->assertDatabaseCount('order_notifications', 1);
});

it('preserves committed orders during Redis outage and relays them after recovery', function () {
    config(['order-events.connection' => 'order-redis-outage']);
    $item = CartItem::factory()->for(Product::factory()->create(['stock_quantity' => 10]))->create(['quantity' => 2]);
    $order = app(CheckoutService::class)->checkout($item->cart->user)->order;
    $result = app(OrderOutboxService::class)->dispatchDue(10);
    expect($result['dispatched'])->toBe(0);
    expect($result['error'])->toBe(RedisException::class);
    $event = OrderOutboxEvent::query()->sole();
    expect($event->status)->toBe(OrderOutboxStatus::Pending);
    expect($event->dispatch_token)->toBeNull();
    expect($event->last_error)->toBe(RedisException::class);
    expect($item->product->fresh()->stock_quantity)->toBe(8);
    $this->assertModelExists($order);
    $this->assertDatabaseCount('order_notifications', 0);
    config(['order-events.connection' => 'order-redis']);
    Redis::purge('order-events');
    $event->update(['available_at' => now('UTC')->subSecond()]);
    expect(app(OrderOutboxService::class)->dispatchDue(10)['dispatched'])->toBe(1);
    runOrderQueueWorker($this->orderQueue);
    expect($event->fresh()->status)->toBe(OrderOutboxStatus::Processed);
    expect($event->fresh()->dispatch_attempts)->toBe(2);
    $this->assertDatabaseCount('order_notifications', 1);
});

it('recovers a lost Redis job through lease expiry and rejects the previous token', function () {
    $event = OrderOutboxEvent::factory()->create();
    app(OrderOutboxService::class)->dispatchDue(10);
    $oldToken = $event->fresh()->dispatch_token;
    Queue::connection('order-redis')->clear($this->orderQueue);
    expect(app(OrderOutboxService::class)->dispatchDue(10)['claimed'])->toBe(0);
    $event->update(['available_at' => now('UTC')->subSecond()]);
    expect(app(OrderOutboxService::class)->dispatchDue(10)['dispatched'])->toBe(1);
    expect($event->fresh()->dispatch_token)->not->toBe($oldToken);
    app(OrderEventProcessingService::class)->process($event->id, $oldToken);
    $this->assertDatabaseCount('order_notifications', 0);
    runOrderQueueWorker($this->orderQueue);
    $this->assertDatabaseCount('order_notifications', 1);
    expect($event->fresh()->dispatch_attempts)->toBe(2);
});

it('recovers a committed claim lost before enqueueing', function () {
    $event = OrderOutboxEvent::factory()->create();
    DB::transaction(fn () => app(OrderOutboxRepositoryInterface::class)->claimDue(10, 300));
    expect(Queue::connection('order-redis')->size($this->orderQueue))->toBe(0);
    $event->update(['available_at' => now('UTC')->subSecond()]);
    app(OrderOutboxService::class)->dispatchDue(10);
    runOrderQueueWorker($this->orderQueue);
    expect($event->fresh()->status)->toBe(OrderOutboxStatus::Processed);
    $this->assertDatabaseCount('order_notifications', 1);
});

it('releases all unsent claims on an outage and respects batch bounds', function () {
    OrderOutboxEvent::factory()->count(3)->create();
    config(['order-events.connection' => 'order-redis-outage']);
    $result = app(OrderOutboxService::class)->dispatchDue(2);
    expect($result['claimed'])->toBe(2);
    expect($result['dispatched'])->toBe(0);
    expect(OrderOutboxEvent::query()->where('status', OrderOutboxStatus::Pending)->count())->toBe(3);
    expect(OrderOutboxEvent::query()->whereNotNull('dispatch_token')->count())->toBe(0);
    expect(OrderOutboxEvent::query()->sum('dispatch_attempts'))->toBe(2);
});

it('rejects synchronous queue configuration without claiming any event', function () {
    $event = OrderOutboxEvent::factory()->create();
    config(['order-events.connection' => 'sync']);
    expect(fn () => app(OrderOutboxService::class)->dispatchDue(10))->toThrow(InvalidArgumentException::class);
    expect($event->fresh()->status)->toBe(OrderOutboxStatus::Pending);
    expect($event->fresh()->dispatch_attempts)->toBe(0);
});

it('bounds processing failures across lease recovery and handles missing events safely', function () {
    $event = OrderOutboxEvent::factory()->queued()->create(['processing_attempts' => 3]);
    $processor = app(OrderEventProcessingService::class);
    $processor->process($event->id, $event->dispatch_token);
    expect($event->fresh()->status)->toBe(OrderOutboxStatus::Failed);
    $this->assertDatabaseCount('order_notifications', 0);
    $processor->process((string) Str::uuid(), (string) Str::uuid());
    expect($event->fresh()->processing_attempts)->toBe(3);
});

it('recovers a killed processor without retaining its uncommitted notification effect', function () {
    $event = OrderOutboxEvent::factory()->queued()->create();
    $input = new InputStream;
    $input->write(json_encode(['action' => 'process-crash', 'id' => $event->id, 'token' => $event->dispatch_token], JSON_THROW_ON_ERROR).PHP_EOL);
    $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/order-event-worker.php')], base_path(), orderEventWorkerEnvironment(), $input, 15);
    $worker->start();
    try {
        $deadline = microtime(true) + 5;
        do {
            if (str_contains($worker->getOutput(), '"effect_uncommitted":true')) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline && $worker->isRunning());
        expect($worker->getOutput())->toContain('"effect_uncommitted":true');
        $this->assertDatabaseCount('order_notifications', 0);
        $worker->stop(0, SIGKILL);
        expect($event->fresh()->status)->toBe(OrderOutboxStatus::Queued);
        $this->assertDatabaseCount('order_notifications', 0);
        $event->update(['available_at' => now('UTC')->subSecond()]);
        app(OrderOutboxService::class)->dispatchDue(10);
        runOrderQueueWorker($this->orderQueue);
        $this->assertDatabaseCount('order_notifications', 1);
        expect($event->fresh()->status)->toBe(OrderOutboxStatus::Processed);
    } finally {
        if ($worker->isRunning()) {
            $worker->stop();
        }
        $input->close();
    }
});
