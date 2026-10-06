<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id()->generatedAs();
            $table->string('name');
            $table->string('sku');
            $table->text('description')->nullable();
            $table->bigInteger('price_minor');
            $table->bigInteger('stock_quantity')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestampsTz();

            $table->index(['status', 'id']);
            $table->index(['status', 'price_minor', 'id']);
            $table->index(['status', 'name', 'id']);
            $table->index(['status', 'created_at', 'id']);
        });

        /** PostgreSQL expression indexes and CHECK constraints have no portable Blueprint API. */
        DB::statement('CREATE UNIQUE INDEX products_sku_normalized_unique ON products (lower(btrim(sku)))');
        DB::statement("ALTER TABLE products
            ADD CONSTRAINT products_price_minor_non_negative CHECK (price_minor >= 0),
            ADD CONSTRAINT products_stock_quantity_non_negative CHECK (stock_quantity >= 0),
            ADD CONSTRAINT products_status_allowed CHECK (status IN ('active', 'inactive')),
            ADD CONSTRAINT products_sku_not_blank CHECK (btrim(sku) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
