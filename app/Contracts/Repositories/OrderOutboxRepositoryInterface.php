<?php

namespace App\Contracts\Repositories;

use App\Enums\OrderEventType;
use App\Models\OrderOutboxEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

interface OrderOutboxRepositoryInterface
{
    public function record(int $orderId, OrderEventType $type, CarbonInterface $occurredAt): OrderOutboxEvent;

    /** @return Collection<int, OrderOutboxEvent> */
    public function claimDue(int $limit, int $leaseSeconds): Collection;

    public function lock(string $eventId): ?OrderOutboxEvent;

    public function save(OrderOutboxEvent $event): void;

    public function releaseClaim(string $eventId, string $token, string $error, int $delaySeconds): void;

    public function recordFailure(string $eventId, string $token, string $error, bool $terminal): void;

    public function recordNotification(string $eventId, int $orderId, OrderEventType $type, CarbonInterface $occurredAt): void;

    /** @return Collection<int, OrderOutboxEvent> */
    public function inspect(?string $status, int $limit): Collection;
}
