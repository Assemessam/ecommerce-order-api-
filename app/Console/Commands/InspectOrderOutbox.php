<?php

namespace App\Console\Commands;

use App\Enums\OrderOutboxStatus;
use App\Models\OrderOutboxEvent;
use App\Services\Order\OrderOutboxService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('orders:outbox {--status= : pending, queued, processed or failed} {--limit=100 : Maximum rows (1-1000)}')]
#[Description('Inspect order outbox status, ownership expiry and sanitized failures')]
class InspectOrderOutbox extends Command
{
    public function handle(OrderOutboxService $outbox): int
    {
        $status = $this->option('status');
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if (($status !== null && OrderOutboxStatus::tryFrom($status) === null) || $limit === false) {
            $this->error('Provide a valid status and limit between 1 and 1000.');

            return self::FAILURE;
        }
        $this->table(['Event ID', 'Type', 'Order', 'Status', 'Dispatches', 'Attempts', 'Due / lease expiry (UTC)', 'Processed (UTC)', 'Failed (UTC)', 'Error class'],
            $outbox->inspect($status, $limit)->map(fn (OrderOutboxEvent $event): array => [
                $event->id, $event->event_type->value, $event->order_id, $event->status->value,
                $event->dispatch_attempts, $event->processing_attempts, $event->available_at->toIso8601String(),
                $event->processed_at?->toIso8601String(), $event->failed_at?->toIso8601String(), $event->last_error,
            ])->all());

        return self::SUCCESS;
    }
}
