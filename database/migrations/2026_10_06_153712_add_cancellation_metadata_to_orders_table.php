<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestampTz('cancelled_at', 6)->nullable();
            $table->timestampTz('inventory_restored_at', 6)->nullable();
            $table->index(['user_id', 'created_at', 'id']);
        });

        DB::statement("ALTER TABLE orders
            DROP CONSTRAINT orders_status_valid,
            ADD CONSTRAINT orders_status_valid CHECK (status IN ('placed', 'cancelled')),
            ADD CONSTRAINT orders_cancellation_state_valid CHECK (
                (status <> 'placed' OR (cancelled_at IS NULL AND inventory_restored_at IS NULL))
                AND (status <> 'cancelled' OR (cancelled_at IS NOT NULL AND inventory_restored_at IS NOT NULL AND cancelled_at = inventory_restored_at)))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE orders
            DROP CONSTRAINT orders_cancellation_state_valid,
            DROP CONSTRAINT orders_status_valid,
            ADD CONSTRAINT orders_status_valid CHECK (status = 'placed')");

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'created_at', 'id']);
            $table->dropColumn(['cancelled_at', 'inventory_restored_at']);
        });
    }
};
