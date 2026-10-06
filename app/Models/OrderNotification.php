<?php

namespace App\Models;

use App\Enums\OrderEventType;
use Database\Factories\OrderNotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['event_id', 'order_id', 'event_type', 'occurred_at'])]
class OrderNotification extends Model
{
    /** @use HasFactory<OrderNotificationFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event_type' => OrderEventType::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
