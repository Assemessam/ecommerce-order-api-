<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id()->generatedAs();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->index()->constrained()->restrictOnDelete();
            $table->bigInteger('quantity');
            $table->timestampsTz();

            $table->unique(['cart_id', 'product_id']);
        });

        /** PostgreSQL CHECK constraints have no portable Blueprint API. */
        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_quantity_positive CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
