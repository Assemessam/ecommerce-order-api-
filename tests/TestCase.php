<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected string $rateLimitPrefix;

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        if (! $app->environment('testing')
            || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.host') !== 'postgres'
            || (string) config('database.connections.pgsql.port') !== '5432'
            || config('database.connections.pgsql.database') !== 'ecommerce_order_api_test'
            || filled(config('database.connections.pgsql.url'))) {
            throw new \RuntimeException(sprintf(
                'Tests must use the isolated Compose PostgreSQL test database (environment=%s, connection=%s, host=%s, database=%s).',
                $app->environment(), config('database.default'), config('database.connections.pgsql.host'), config('database.connections.pgsql.database'),
            ));
        }

        if ((string) config('database.redis.rate-limits.database') !== '7'
            || config('database.redis.rate-limits.host') !== 'redis'
            || filled(config('database.redis.rate-limits.url'))) {
            throw new \RuntimeException('Rate limit tests require the isolated project Redis database 7.');
        }

        $this->rateLimitPrefix = 'ecommerce:rate-limits:testing:'.Str::uuid().':';
        config(['database.redis.rate-limits.prefix' => $this->rateLimitPrefix]);

        return $app;
    }

    protected function tearDown(): void
    {
        if (isset($this->app, $this->rateLimitPrefix)) {
            $this->clearRateLimitKeys();
        }

        parent::tearDown();
    }

    public function clearRateLimitKeys(): void
    {
        $connection = Redis::connection('rate-limits');
        foreach ($connection->keys('*') as $key) {
            if (! str_starts_with($key, $this->rateLimitPrefix)) {
                throw new \RuntimeException('Refusing to remove a key outside this test namespace.');
            }
            $connection->del(substr($key, strlen($this->rateLimitPrefix)));
        }
    }

    /** Advance only this test's native Redis windows without waiting an hour. */
    public function advanceRateLimitWindows(): void
    {
        $connection = Redis::connection('rate-limits');
        foreach ($connection->keys('*') as $key) {
            $connection->hset(substr($key, strlen($this->rateLimitPrefix)), 'end', time() - 1);
        }
    }
}
