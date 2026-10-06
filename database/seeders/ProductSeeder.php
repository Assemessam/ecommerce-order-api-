<?php

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Services\Product\ProductCatalogueCache;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductSeeder extends Seeder
{
    public function run(ProductCatalogueCache $catalogueCache): void
    {
        $products = [
            ['sku' => 'DEMO-KEYBOARD', 'name' => 'Wireless Keyboard', 'description' => 'Compact rechargeable keyboard.', 'price_minor' => 4999, 'stock_quantity' => 25, 'status' => ProductStatus::Active],
            ['sku' => 'DEMO-MUG', 'name' => 'Insulated Travel Mug', 'description' => 'Stainless steel mug for daily travel.', 'price_minor' => 1899, 'stock_quantity' => 40, 'status' => ProductStatus::Active],
            ['sku' => 'DEMO-LAMP', 'name' => 'Adjustable Desk Lamp', 'description' => 'LED lamp with adjustable brightness.', 'price_minor' => 3499, 'stock_quantity' => 0, 'status' => ProductStatus::Active],
            ['sku' => 'DEMO-BACKPACK', 'name' => 'Canvas Backpack', 'description' => 'Durable everyday backpack.', 'price_minor' => 6500, 'stock_quantity' => 12, 'status' => ProductStatus::Active],
            ['sku' => 'DEMO-CABLE', 'name' => 'USB-C Cable', 'description' => 'Two metre charging cable.', 'price_minor' => 999, 'stock_quantity' => 30, 'status' => ProductStatus::Inactive],
            ['sku' => 'DEMO-STAND', 'name' => 'Laptop Stand', 'description' => 'Discontinued aluminium desk stand.', 'price_minor' => 2999, 'stock_quantity' => 0, 'status' => ProductStatus::Inactive],
        ];

        DB::transaction(function () use ($products, $catalogueCache): void {
            $created = false;

            foreach ($products as $product) {
                $created = Product::query()->firstOrCreate(['sku' => $product['sku']], $product)->wasRecentlyCreated || $created;
            }

            if ($created) {
                $catalogueCache->invalidateAfterCommit();
            }
        });
    }
}
