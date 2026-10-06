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
    || filled(config('database.connections.pgsql.url'))) {
    throw new RuntimeException('Cart concurrency workers require the isolated Compose PostgreSQL test database.');
}

$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
DB::select('SELECT set_config(?, ?, false)', ['application_name', $payload['application_name']]);
DB::select('SELECT set_config(?, ?, false)', ['lock_timeout', '10s']);
$connection = DB::selectOne('SELECT pg_backend_pid() AS pid, current_database() AS database');
$request = Request::create('/api/cart/items', 'POST', server: [
    'HTTP_ACCEPT' => 'application/json',
    'CONTENT_TYPE' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer '.$payload['token'],
], content: json_encode(['product_id' => $payload['product_id'], 'quantity' => $payload['quantity']], JSON_THROW_ON_ERROR));
$response = $kernel->handle($request);
$kernel->terminate($request, $response);

echo json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR),
    'pid' => $connection->pid,
    'database' => $connection->database,
], JSON_THROW_ON_ERROR);
