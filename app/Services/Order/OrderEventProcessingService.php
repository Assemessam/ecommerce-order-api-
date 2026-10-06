<?php

namespace App\Services\Order;

use App\Contracts\Repositories\OrderOutboxRepositoryInterface;
use App\Enums\OrderEventType;
use App\Enums\OrderOutboxStatus;
use App\Events\OrderCancelled;
use App\Events\OrderPlaced;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Throwable;

class OrderEventProcessingService
{
    public function __construct(private OrderOutboxRepositoryInterface $events, private Dispatcher $dispatcher) {}

    public function process(string $eventId, string $token): void
    {
        try {
            DB::transaction(function () use ($eventId, $token): void {
                $event = $this->events->lock($eventId);
                if ($event === null || $event->status !== OrderOutboxStatus::Queued || $event->dispatch_token !== $token) {
                    return;
                }
                if ($event->processing_attempts >= 3) {
                    $this->events->recordFailure($eventId, $token, 'Processing attempts exhausted', terminal: true);

                    return;
                }
                $notification = match ($event->event_type) {
                    OrderEventType::OrderPlaced => new OrderPlaced($event->id, $event->order_id, $event->occurred_at),
                    OrderEventType::OrderCancelled => new OrderCancelled($event->id, $event->order_id, $event->occurred_at),
                };
                $this->dispatcher->dispatch($notification);
                $event->forceFill(['status' => OrderOutboxStatus::Processed, 'processed_at' => now('UTC'),
                    'processing_attempts' => $event->processing_attempts + 1, 'last_error' => null]);
                $this->events->save($event);
            }, attempts: 3);
        } catch (Throwable $exception) {
            $this->events->recordFailure($eventId, $token, $exception::class, terminal: false);
            throw $exception;
        }
    }

    public function failed(string $eventId, string $token, ?Throwable $exception): void
    {
        $this->events->recordFailure($eventId, $token, $exception === null ? 'Job failed' : $exception::class, terminal: true);
    }
}
