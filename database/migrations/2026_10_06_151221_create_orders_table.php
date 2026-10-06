<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id()->generatedAs();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('placed');
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor');
            $table->bigInteger('total_minor');
            $table->foreignId('promotion_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('promotion_code_snapshot', 64)->nullable();
            $table->string('promotion_type_snapshot', 16)->nullable();
            $table->bigInteger('promotion_value_snapshot')->nullable();
            $table->bigInteger('promotion_maximum_discount_minor_snapshot')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->timestampTz('placed_at', 6);
            $table->timestampsTz();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'placed_at', 'id']);
            $table->index('promotion_id');
        });

        DB::statement("ALTER TABLE orders
            ADD CONSTRAINT orders_status_valid CHECK (status = 'placed'),
            ADD CONSTRAINT orders_currency_valid CHECK (currency ~ '^[A-Z]{3}$'),
            ADD CONSTRAINT orders_money_valid CHECK (subtotal_minor >= 0 AND discount_minor >= 0 AND discount_minor <= subtotal_minor AND total_minor >= 0 AND total_minor = subtotal_minor - discount_minor),
            ADD CONSTRAINT orders_idempotency_key_valid CHECK (idempotency_key IS NULL OR idempotency_key ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$'),
            ADD CONSTRAINT orders_promotion_snapshot_valid CHECK (
                (promotion_id IS NULL AND promotion_code_snapshot IS NULL AND promotion_type_snapshot IS NULL AND promotion_value_snapshot IS NULL AND promotion_maximum_discount_minor_snapshot IS NULL AND discount_minor = 0)
                OR (promotion_id IS NOT NULL AND promotion_code_snapshot IS NOT NULL AND promotion_type_snapshot IS NOT NULL AND promotion_value_snapshot IS NOT NULL
                    AND promotion_code_snapshot ~ '^[A-Z0-9][A-Z0-9_-]{0,63}$'
                    AND ((promotion_type_snapshot = 'percentage' AND promotion_value_snapshot BETWEEN 1 AND 10000) OR (promotion_type_snapshot = 'fixed' AND promotion_value_snapshot > 0))
                    AND (promotion_maximum_discount_minor_snapshot IS NULL OR promotion_maximum_discount_minor_snapshot > 0)))");
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
