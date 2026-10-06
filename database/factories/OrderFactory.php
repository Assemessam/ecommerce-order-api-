<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => OrderStatus::Placed,
            'currency' => config('catalogue.currency'),
            'subtotal_minor' => 1000,
            'discount_minor' => 0,
            'total_minor' => 1000,
            'placed_at' => now('UTC'),
        ];
    }
}
