<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderItem> */
class OrderItemFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_id' => Product::factory(),
            'product_name' => 'Snapshot Product',
            'product_sku' => 'SNAPSHOT-SKU',
            'quantity' => 1,
            'unit_price_minor' => 1000,
            'line_subtotal_minor' => 1000,
        ];
    }
}
