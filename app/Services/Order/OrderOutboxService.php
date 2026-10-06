<?php

namespace App\Services\Order;

use App\Contracts\Repositories\OrderOutboxRepositoryInterface;
use App\Enums\OrderEventType;
use App\Enums\OrderOutboxStatus;
use App\Jobs\ProcessOrderEvent;
use App\Models\Order;
use App\Models\OrderOutboxEvent;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Throwable;

class OrderOutboxService
{
    public function __construct(private OrderOutboxRepositoryInterface $events, private QueueFactory $queues) {}

    public function record(Order $order, OrderEventType $type): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Order events must be recorded in the business transaction.');
        }
        $occurredAt = $type === OrderEventType::OrderPlaced ? $order->placed_at : $order->cancelled_at;
        $this->events->record($order->id, $type, $occurredAt);
    }

    /** @return array{claimed: int, dispatched: int, error: ?string} */
    public function dispatchDue(int $limit): array
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Run the outbox relay outside business transactions.');
        }
        $connection = (string) config('order-events.connection');
        $driver = config('queue.connections.'.$connection.'.driver');
        if (! in_array($driver, ['redis', 'database'], true)) {
            throw new InvalidArgumentException('Order events require an asynchronous Redis or database queue.');
        }
        $events = DB::transaction(fn (): Collection => $this->events->claimDue(max(1, min($limit, 1000)), (int) config('order-events.lease_seconds')), attempts: 3);
        $dispatched = 0;
        foreach ($events as $index => $event) {
            try {
                $this->queues->connection($connection)->push(new ProcessOrderEvent($event->id, $event->dispatch_token), queue: (string) config('order-events.queue'));
                $dispatched++;
            } catch (Throwable $exception) {
                $error = $exception::class;
                foreach ($events->slice($index) as $unsent) {
                    $this->events->releaseClaim($unsent->id, $unsent->dispatch_token, $error, (int) config('order-events.dispatch_retry_seconds'));
                }
                try {
                    if (Cache::store('file')->add('order-outbox-warning:'.hash('sha256', $connection), true, 60)) {
                        Log::warning('Order outbox queue dispatch failed; PostgreSQL events retained.', ['event_id' => $event->id, 'exception_class' => $error]);
                    }
                } catch (Throwable) {
                    // Diagnostics must not prevent recovery of durable events.
                }

                return ['claimed' => $events->count(), 'dispatched' => $dispatched, 'error' => $error];
            }
        }

        return ['claimed' => $events->count(), 'dispatched' => $dispatched, 'error' => null];
    }

    public function retry(string $eventId): bool
    {
        return DB::transaction(function () use ($eventId): bool {
            $event = $this->events->lock($eventId);
            if ($event === null || $event->status === OrderOutboxStatus::Processed
                || ($event->status === OrderOutboxStatus::Queued && $event->available_at->isFuture())) {
                return false;
            }
            $event->forceFill(['status' => OrderOutboxStatus::Pending, 'dispatch_token' => null,
                'available_at' => now('UTC'), 'failed_at' => null, 'processing_attempts' => 0, 'last_error' => null]);
            $this->events->save($event);

            return true;
        }, attempts: 3);
    }

    /** @return Collection<int, OrderOutboxEvent> */
    public function inspect(?string $status, int $limit): Collection
    {
        return $this->events->inspect($status, max(1, min($limit, 1000)));
    }
}
