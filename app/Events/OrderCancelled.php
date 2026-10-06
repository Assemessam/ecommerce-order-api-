<?php

namespace App\Events;

use Carbon\CarbonImmutable;

final readonly class OrderCancelled
{
    public function __construct(
        public string $eventId,
        public int $orderId,
        public CarbonImmutable $occurredAt,
    ) {}
}
