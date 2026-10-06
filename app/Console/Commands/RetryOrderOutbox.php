<?php

namespace App\Console\Commands;

use App\Services\Order\OrderOutboxService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('orders:retry-outbox {eventId : Failed, pending or expired event UUID}')]
#[Description('Reset a recoverable event for a new outbox delivery; preserve processed effects')]
class RetryOrderOutbox extends Command
{
    public function handle(OrderOutboxService $outbox): int
    {
        $eventId = $this->argument('eventId');
        if (! Str::isUuid($eventId) || ! $outbox->retry($eventId)) {
            $this->error('Event missing, already processed, invalid, or has an active dispatch lease.');

            return self::FAILURE;
        }
        $this->info('Event pending. Run orders:dispatch-outbox or wait for the scheduler.');

        return self::SUCCESS;
    }
}
