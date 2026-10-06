<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\OrderOutboxRepositoryInterface;
use App\Enums\OrderEventType;
use App\Enums\OrderOutboxStatus;
use App\Models\OrderNotification;
use App\Models\OrderOutboxEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EloquentOrderOutboxRepository implements OrderOutboxRepositoryInterface
{
    public function record(int $orderId, OrderEventType $type, CarbonInterface $occurredAt): OrderOutboxEvent
    {
        return OrderOutboxEvent::query()->create([
            'id' => (string) Str::uuid(), 'order_id' => $orderId, 'event_type' => $type,
            'occurred_at' => $occurredAt, 'available_at' => now('UTC'),
        ]);
    }

    /** @return Collection<int, OrderOutboxEvent> */
    public function claimDue(int $limit, int $leaseSeconds): Collection
    {
        $now = now('UTC');
        $events = OrderOutboxEvent::query()->whereIn('status', [OrderOutboxStatus::Pending, OrderOutboxStatus::Queued])
            ->where('available_at', '<=', $now->format('Y-m-d H:i:s.uP'))->orderBy('available_at')->orderBy('id')
            ->limit($limit)->lock('FOR UPDATE SKIP LOCKED')->get();

        foreach ($events as $event) {
            $event->forceFill([
                'status' => OrderOutboxStatus::Queued, 'dispatch_token' => (string) Str::uuid(),
                'dispatch_attempts' => $event->dispatch_attempts + 1,
                'last_dispatched_at' => $now, 'available_at' => $now->copy()->addSeconds($leaseSeconds),
            ])->save();
        }

        return $events;
    }

    public function lock(string $eventId): ?OrderOutboxEvent
    {
        return OrderOutboxEvent::query()->whereKey($eventId)->lockForUpdate()->first();
    }

    public function save(OrderOutboxEvent $event): void
    {
        $event->save();
    }

    public function releaseClaim(string $eventId, string $token, string $error, int $delaySeconds): void
    {
        OrderOutboxEvent::query()->whereKey($eventId)->where('dispatch_token', $token)->where('status', OrderOutboxStatus::Queued)
            ->update(['status' => OrderOutboxStatus::Pending, 'dispatch_token' => null,
                'available_at' => now('UTC')->addSeconds($delaySeconds), 'last_error' => $error]);
    }

    public function recordFailure(string $eventId, string $token, string $error, bool $terminal): void
    {
        $attributes = ['last_error' => $error];
        if ($terminal) {
            $attributes += ['status' => OrderOutboxStatus::Failed, 'failed_at' => now('UTC')];
        } else {
            $attributes['processing_attempts'] = DB::raw('processing_attempts + 1');
        }
        OrderOutboxEvent::query()->whereKey($eventId)->where('dispatch_token', $token)->where('status', OrderOutboxStatus::Queued)->update($attributes);
    }

    public function recordNotification(string $eventId, int $orderId, OrderEventType $type, CarbonInterface $occurredAt): void
    {
        OrderNotification::query()->create(['event_id' => $eventId, 'order_id' => $orderId, 'event_type' => $type, 'occurred_at' => $occurredAt]);
    }

    /** @return Collection<int, OrderOutboxEvent> */
    public function inspect(?string $status, int $limit): Collection
    {
        return OrderOutboxEvent::query()->when($status, fn (Builder $query): Builder => $query->where('status', $status))->orderBy('created_at')->orderBy('id')->limit($limit)->get();
    }
}
