<?php

namespace App\Listeners;

use App\Contracts\Repositories\OrderOutboxRepositoryInterface;
use App\Enums\OrderEventType;
use App\Events\OrderCancelled;
use App\Events\OrderPlaced;

class RecordOrderNotification
{
    public function __construct(private OrderOutboxRepositoryInterface $events) {}

    public function handle(OrderPlaced|OrderCancelled $event): void
    {
        $this->events->recordNotification($event->eventId, $event->orderId,
            $event instanceof OrderPlaced ? OrderEventType::OrderPlaced : OrderEventType::OrderCancelled, $event->occurredAt);
    }
}
