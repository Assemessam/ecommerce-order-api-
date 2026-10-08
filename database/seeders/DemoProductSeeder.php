<?php

namespace Database\Seeders;

use App\DTOs\Product\CreateProductData;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;
use App\Services\Product\ProductService;
use Carbon\CarbonImmutable;
use LogicException;

class DemoProductSeeder extends DemoSeeder
{
    public function __construct(private ProductService $products) {}

    protected function seed(): void
    {
        $administrator = User::query()->where('email', 'demo-admin@example.test')->firstOrFail();
        $createdAt = CarbonImmutable::now('UTC')->subDays(90);

        /** @var list<array{sku: string, name: string, description: string, price_minor: int, stock_quantity: int, status?: string}> $products */
        $products = [
            ['sku' => 'DEMO-V1-KEYBOARD', 'name' => 'Wireless Keyboard', 'description' => 'Compact rechargeable keyboard with quiet keys for a home office.', 'price_minor' => 4999, 'stock_quantity' => 50],
            ['sku' => 'DEMO-V1-MOUSE', 'name' => 'Ergonomic Wireless Mouse', 'description' => 'Contoured wireless mouse with adjustable sensitivity and a USB receiver.', 'price_minor' => 2499, 'stock_quantity' => 80],
            ['sku' => 'DEMO-V1-HEADPHONES', 'name' => 'Noise Cancelling Headphones', 'description' => 'Over-ear headphones with active noise cancellation and a travel case.', 'price_minor' => 12999, 'stock_quantity' => 20],
            ['sku' => 'DEMO-V1-MONITOR', 'name' => '27-inch 4K Monitor', 'description' => 'Sharp desktop display with an adjustable stand and HDMI connectivity.', 'price_minor' => 34999, 'stock_quantity' => 10],
            ['sku' => 'DEMO-V1-MUG', 'name' => 'Insulated Travel Mug', 'description' => 'Stainless steel travel mug with a spill-resistant lid for daily commuting.', 'price_minor' => 1899, 'stock_quantity' => 40],
            ['sku' => 'DEMO-V1-STAND', 'name' => 'Aluminium Laptop Stand', 'description' => 'Ventilated laptop riser that brings the screen to a comfortable desk height.', 'price_minor' => 2999, 'stock_quantity' => 25],
            ['sku' => 'DEMO-V1-CABLE', 'name' => 'USB-C Charging Cable', 'description' => 'Durable two metre USB-C cable for charging and everyday data transfers.', 'price_minor' => 999, 'stock_quantity' => 100],
            ['sku' => 'DEMO-V1-BACKPACK', 'name' => 'Canvas Commuter Backpack', 'description' => 'Canvas daypack with a padded laptop compartment and useful interior pockets.', 'price_minor' => 6500, 'stock_quantity' => 30],
            ['sku' => 'DEMO-V1-NOTEBOOK', 'name' => 'Recycled Paper Notebook', 'description' => 'Affordable ruled notebook made with recycled paper for daily notes.', 'price_minor' => 499, 'stock_quantity' => 200],
            ['sku' => 'DEMO-V1-ORGANIZER', 'name' => 'Bamboo Desk Organizer', 'description' => 'Bamboo desktop tray with compartments for stationery and small accessories.', 'price_minor' => 1799, 'stock_quantity' => 35],
            ['sku' => 'DEMO-V1-LAMP', 'name' => 'Adjustable LED Desk Lamp', 'description' => 'Dimmable LED reading lamp awaiting its next inventory delivery.', 'price_minor' => 3499, 'stock_quantity' => 0],
            ['sku' => 'DEMO-V1-LIMITED', 'name' => 'Compact Phone Tripod', 'description' => 'Foldable phone tripod with only two units remaining in the current batch.', 'price_minor' => 1299, 'stock_quantity' => 2],
            ['sku' => 'DEMO-V1-SPEAKER', 'name' => 'Portable Bluetooth Speaker', 'description' => 'Rechargeable portable speaker for clear audio at home or on a day trip.', 'price_minor' => 7999, 'stock_quantity' => 18],
            ['sku' => 'DEMO-V1-HUB', 'name' => 'USB-C Desktop Hub', 'description' => 'Desktop adapter with HDMI, USB ports, and pass-through laptop charging.', 'price_minor' => 5999, 'stock_quantity' => 25],
            ['sku' => 'DEMO-V1-CHARGER', 'name' => 'Retired Wireless Charger', 'description' => 'Retired charging pad retained for administrator catalogue demonstrations.', 'price_minor' => 3999, 'stock_quantity' => 7, 'status' => ProductStatus::Inactive->value],
            ['sku' => 'DEMO-V1-MAT', 'name' => 'Discontinued Desk Mat', 'description' => 'Discontinued desk mat retained as an inactive item with no remaining stock.', 'price_minor' => 1999, 'stock_quantity' => 0, 'status' => ProductStatus::Inactive->value],
        ];

        foreach ($products as $product) {
            $existingProduct = Product::query()->whereRaw('lower(btrim(sku)) = ?', [strtolower($product['sku'])])->first();

            if ($existingProduct !== null) {
                if ($existingProduct->name !== $product['name']) {
                    throw new LogicException('Demo product SKU collision: '.$product['sku']);
                }

                continue;
            }

            $createdProduct = $this->products->createProduct($administrator, CreateProductData::fromArray([
                'status' => ProductStatus::Active->value,
                ...$product,
            ]));
            $createdProduct->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
        }
    }
}
