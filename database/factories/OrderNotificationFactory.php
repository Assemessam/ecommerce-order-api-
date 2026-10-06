<?php

namespace Database\Factories;

use App\Models\OrderNotification;
use App\Models\OrderOutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderNotification> */
class OrderNotificationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['event_id' => OrderOutboxEvent::factory(),
            'order_id' => fn (array $attributes): int => OrderOutboxEvent::query()->findOrFail($attributes['event_id'])->order_id,
            'event_type' => fn (array $attributes): string => OrderOutboxEvent::query()->findOrFail($attributes['event_id'])->event_type->value,
            'occurred_at' => fn (array $attributes): CarbonImmutable => OrderOutboxEvent::query()->findOrFail($attributes['event_id'])->occurred_at];
    }
}
