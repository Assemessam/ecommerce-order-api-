<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id()->generatedAs();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->string('product_sku');
            $table->bigInteger('quantity');
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('line_subtotal_minor');
            $table->timestampsTz();
            $table->unique(['order_id', 'product_id']);
            $table->index('product_id');
        });

        DB::statement('ALTER TABLE order_items
            ADD CONSTRAINT order_items_quantity_positive CHECK (quantity > 0),
            ADD CONSTRAINT order_items_money_valid CHECK (unit_price_minor >= 0 AND line_subtotal_minor >= 0 AND line_subtotal_minor::numeric = quantity::numeric * unit_price_minor::numeric)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
