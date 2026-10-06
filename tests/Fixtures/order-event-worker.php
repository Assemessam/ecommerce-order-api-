<?php

use App\Contracts\Repositories\OrderOutboxRepositoryInterface;
use App\Events\OrderCancelled;
use App\Events\OrderPlaced;
use App\Services\Order\OrderEventProcessingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.host') !== 'postgres'
    || (string) config('database.connections.pgsql.port') !== '5432'
    || config('database.connections.pgsql.database') !== 'ecommerce_order_api_test'
    || filled(config('database.connections.pgsql.url'))
    || (string) config('database.redis.order-events.database') !== '5'
    || filled(config('database.redis.order-events.url'))) {
    throw new RuntimeException('Worker must use the isolated PostgreSQL and Redis test databases.');
}
$input = json_decode(trim(fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
DB::statement("SET lock_timeout = '10s'");
DB::select("SELECT set_config('application_name', ?, false)", [$input['name'] ?? 'outbox-test-worker']);
$pid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;

if ($input['action'] === 'claim') {
    DB::beginTransaction();
    try {
        $events = app(OrderOutboxRepositoryInterface::class)->claimDue($input['limit'], 300);
        echo json_encode(['pid' => $pid, 'ids' => $events->pluck('id')->all()], JSON_THROW_ON_ERROR).PHP_EOL;
        flush();
        stream_set_timeout(STDIN, 10);
        if (trim((string) fgets(STDIN)) !== 'commit') {
            throw new RuntimeException('Claim barrier was not released');
        }
        DB::commit();
    } catch (Throwable $exception) {
        DB::rollBack();
        throw $exception;
    }
} elseif ($input['action'] === 'process-crash') {
    Event::listen(OrderPlaced::class, function () use ($pid): void {
        echo json_encode(['effect_uncommitted' => true, 'pid' => $pid], JSON_THROW_ON_ERROR).PHP_EOL;
        flush();
        stream_set_timeout(STDIN, 10);
        if (fgets(STDIN) === false) {
            throw new RuntimeException('Crash barrier ended');
        }
    });
    app(OrderEventProcessingService::class)->process($input['id'], $input['token']);
} elseif ($input['action'] === 'process') {
    echo json_encode(['ready' => true, 'pid' => $pid], JSON_THROW_ON_ERROR).PHP_EOL;
    flush();
    app(OrderEventProcessingService::class)->process($input['id'], $input['token']);
    echo json_encode(['processed' => true, 'pid' => $pid], JSON_THROW_ON_ERROR).PHP_EOL;
} elseif ($input['action'] === 'queue') {
    if ($input['fail'] ?? false) {
        $failure = function (): never {
            throw new RuntimeException('Injected processing failure');
        };
        Event::listen(OrderPlaced::class, $failure);
        Event::listen(OrderCancelled::class, $failure);
    }
    Artisan::call('queue:work', ['connection' => 'order-redis', '--queue' => $input['queue'], '--once' => true,
        '--sleep' => 0, '--timeout' => 30, '--tries' => 3, '--no-interaction' => true]);
    echo json_encode(['worker_exit' => 0, 'pid' => $pid], JSON_THROW_ON_ERROR).PHP_EOL;
} else {
    throw new RuntimeException('Unknown test worker action');
}
