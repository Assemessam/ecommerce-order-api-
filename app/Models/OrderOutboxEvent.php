<?php

namespace App\Models;

use App\Enums\OrderEventType;
use App\Enums\OrderOutboxStatus;
use Database\Factories\OrderOutboxEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'order_id', 'event_type', 'occurred_at', 'status', 'dispatch_token', 'dispatch_attempts', 'processing_attempts', 'available_at', 'last_dispatched_at', 'processed_at', 'failed_at', 'last_error'])]
class OrderOutboxEvent extends Model
{
    /** @use HasFactory<OrderOutboxEventFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event_type' => OrderEventType::class,
            'status' => OrderOutboxStatus::class,
            'occurred_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'last_dispatched_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'dispatch_attempts' => 'integer',
            'processing_attempts' => 'integer',
        ];
    }
}
