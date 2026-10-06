<?php

namespace Database\Factories;

use App\Enums\OrderEventType;
use App\Enums\OrderOutboxStatus;
use App\Models\Order;
use App\Models\OrderOutboxEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OrderOutboxEvent> */
class OrderOutboxEventFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['id' => (string) Str::uuid(), 'order_id' => Order::factory(),
            'event_type' => OrderEventType::OrderPlaced, 'occurred_at' => now('UTC'), 'available_at' => now('UTC')];
    }

    public function queued(): static
    {
        return $this->state(fn (): array => ['status' => OrderOutboxStatus::Queued,
            'dispatch_token' => (string) Str::uuid(), 'dispatch_attempts' => 1,
            'last_dispatched_at' => now('UTC'), 'available_at' => now('UTC')->addMinutes(5)]);
    }
}
