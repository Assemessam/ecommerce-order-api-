<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Tests\Fixtures\ObservedThrottleRequests;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (! $app->environment('testing') || config('database.connections.pgsql.database') !== 'ecommerce_order_api_test'
    || config('database.connections.pgsql.host') !== 'postgres' || filled(config('database.connections.pgsql.url'))
    || (string) config('database.redis.rate-limits.database') !== '7' || filled(config('database.redis.rate-limits.url'))
    || config('database.redis.rate-limits.host') !== 'redis'
    || ! str_starts_with(config('database.redis.rate-limits.prefix'), 'ecommerce:rate-limits:testing:')) {
    throw new RuntimeException('HTTP limiter workers require isolated PostgreSQL and Redis test databases.');
}

$input = json_decode(trim(fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
config($input['config'] ?? []);

$app['router']->aliasMiddleware('throttle', ObservedThrottleRequests::class);

$request = Request::create($input['path'], $input['method'], server: [
    'REMOTE_ADDR' => '192.0.2.10', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
    'HTTP_AUTHORIZATION' => isset($input['token']) ? 'Bearer '.$input['token'] : '',
], content: json_encode($input['body'] ?? [], JSON_THROW_ON_ERROR));
foreach ($input['headers'] ?? [] as $name => $value) {
    $request->headers->set($name, $value);
}
$response = $kernel->handle($request);
$kernel->terminate($request, $response);
echo json_encode(['status' => $response->getStatusCode(), 'remaining' => $response->headers->get('X-RateLimit-Remaining'),
    'body' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR).PHP_EOL;
