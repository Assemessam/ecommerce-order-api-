<?php

namespace App\Console\Commands;

use App\Services\Order\OrderOutboxService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('orders:dispatch-outbox {--limit= : Maximum events to claim (1-1000)}')]
#[Description('Relay due PostgreSQL order events to the background queue')]
class DispatchOrderOutbox extends Command
{
    public function handle(OrderOutboxService $outbox): int
    {
        $limit = $this->option('limit') ?? config('order-events.batch_size');
        if (filter_var($limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]) === false) {
            $this->error('Limit must be between 1 and 1000.');

            return self::FAILURE;
        }
        try {
            $result = $outbox->dispatchDue((int) $limit);
        } catch (Throwable $exception) {
            $this->error('Outbox relay failed: '.$exception::class);

            return self::FAILURE;
        }
        $this->info('Claimed '.$result['claimed'].'; dispatched '.$result['dispatched'].'.');
        if ($result['error'] !== null) {
            $this->error('Queue unavailable: '.$result['error'].'. Events retained for retry.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
