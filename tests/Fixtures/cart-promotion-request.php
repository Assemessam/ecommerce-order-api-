<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (! $app->environment('testing')
    || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.host') !== 'postgres'
    || (string) config('database.connections.pgsql.port') !== '5432'
    || config('database.connections.pgsql.database') !== 'ecommerce_order_api_test'
    || filled(config('database.connections.pgsql.url'))
    || (string) config('database.redis.rate-limits.database') !== '7'
    || filled(config('database.redis.rate-limits.url'))
    || ! str_starts_with(config('database.redis.rate-limits.prefix'), 'ecommerce:rate-limits:testing:')) {
    throw new RuntimeException('Cart concurrency workers require the isolated Compose PostgreSQL test database.');
}

$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
DB::select('SELECT set_config(?, ?, false)', ['application_name', $payload['application_name']]);
DB::select('SELECT set_config(?, ?, false)', ['lock_timeout', '10s']);
$connection = DB::selectOne('SELECT pg_backend_pid() AS pid, current_database() AS database');
DB::enableQueryLog();
$server = [
    'HTTP_ACCEPT' => 'application/json',
    'CONTENT_TYPE' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer '.$payload['token'],
];

if (isset($payload['idempotency_key'])) {
    $server['HTTP_IDEMPOTENCY_KEY'] = $payload['idempotency_key'];
}

$request = Request::create($payload['path'], $payload['method'], server: $server, content: json_encode($payload['body'], JSON_THROW_ON_ERROR));
$response = $kernel->handle($request);
$kernel->terminate($request, $response);

echo json_encode([
    'status' => $response->getStatusCode(),
    'body' => $response->getContent() === '' ? null : json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR),
    'pid' => $connection->pid,
    'database' => $connection->database,
    'order_updates' => collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with($query['query'], 'update "orders"'))->count(),
    'inventory_restores' => collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with($query['query'], 'update "products"') && str_contains($query['query'], '+'))->count(),
    'locks' => collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'for update'))->pluck('query')->all(),
], JSON_THROW_ON_ERROR);
