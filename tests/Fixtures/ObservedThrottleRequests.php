<?php

namespace Tests\Fixtures;

use App\Http\Middleware\ThrottleApiRequests;
use RuntimeException;

class ObservedThrottleRequests extends ThrottleApiRequests
{
    protected function tooManyAttempts(mixed $key, mixed $maxAttempts, mixed $decaySeconds): bool
    {
        $denied = parent::tooManyAttempts($key, $maxAttempts, $decaySeconds);
        echo json_encode(['admission' => true, 'pid' => getmypid(),
            'redis_client_id' => $this->getRedisConnection()->client()->rawCommand('CLIENT', 'ID')], JSON_THROW_ON_ERROR).PHP_EOL;
        flush();
        stream_set_timeout(STDIN, 10);
        if (trim((string) fgets(STDIN)) !== 'release') {
            throw new RuntimeException('Admission barrier was not released.');
        }

        return $denied;
    }
}
