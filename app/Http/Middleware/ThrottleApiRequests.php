<?php

namespace App\Http\Middleware;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Limiters\DurationLimiter;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use RedisException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class ThrottleApiRequests extends ThrottleRequestsWithRedis
{
    /**
     * Laravel 13.34 checks then hits separately and ignores a rejected acquisition.
     * Reserve once with Laravel's atomic Lua limiter before admitting the request.
     */
    protected function tooManyAttempts($key, $maxAttempts, $decaySeconds): bool
    {
        try {
            $limiter = new DurationLimiter($this->getRedisConnection(), $key, $maxAttempts, $decaySeconds);
            $acquired = $limiter->acquire();
            [$this->decaysAt[$key], $this->remaining[$key]] = [$limiter->decaysAt, $limiter->remaining];

            return ! $acquired;
        } catch (RedisException) {
            throw new ServiceUnavailableHttpException(null, 'The service is temporarily unavailable.');
        }
    }

    /** Admission already reserved the slot. Policies count all requests before execution. */
    protected function hit($key, $maxAttempts, $decaySeconds): void {}

    protected function getRedisConnection(): Connection
    {
        return $this->redis->connection((string) config('rate-limits.connection'));
    }
}
