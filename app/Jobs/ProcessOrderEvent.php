<?php

namespace App\Jobs;

use App\Services\Order\OrderEventProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessOrderEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(public string $eventId, public string $dispatchToken) {}

    public function handle(OrderEventProcessingService $processor): void
    {
        $processor->process($this->eventId, $this->dispatchToken);
    }

    public function failed(?Throwable $exception): void
    {
        app(OrderEventProcessingService::class)->failed($this->eventId, $this->dispatchToken, $exception);
    }
}
