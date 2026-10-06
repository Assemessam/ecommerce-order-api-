<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id()->generatedAs();
            $table->string('code', 64);
            $table->string('type', 16);
            $table->bigInteger('value');
            $table->bigInteger('minimum_cart_amount_minor')->default(0);
            $table->bigInteger('maximum_discount_minor')->nullable();
            $table->timestampTz('starts_at', 6)->nullable();
            $table->timestampTz('expires_at', 6)->nullable();
            $table->bigInteger('global_usage_limit')->nullable();
            $table->bigInteger('per_customer_usage_limit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique('code');
        });

        /** Canonical ASCII codes make ordinary indexed equality lookup equivalent to normalized lookup. */
        DB::statement("ALTER TABLE promotions
            ADD CONSTRAINT promotions_code_normalized CHECK (code ~ '^[A-Z0-9][A-Z0-9_-]{0,63}$'),
            ADD CONSTRAINT promotions_type_allowed CHECK (type IN ('percentage', 'fixed')),
            ADD CONSTRAINT promotions_value_valid CHECK (value > 0 AND (type <> 'percentage' OR value <= 10000)),
            ADD CONSTRAINT promotions_minimum_non_negative CHECK (minimum_cart_amount_minor >= 0),
            ADD CONSTRAINT promotions_maximum_positive CHECK (maximum_discount_minor IS NULL OR maximum_discount_minor > 0),
            ADD CONSTRAINT promotions_global_limit_positive CHECK (global_usage_limit IS NULL OR global_usage_limit > 0),
            ADD CONSTRAINT promotions_customer_limit_positive CHECK (per_customer_usage_limit IS NULL OR per_customer_usage_limit > 0),
            ADD CONSTRAINT promotions_dates_ordered CHECK (starts_at IS NULL OR expires_at IS NULL OR starts_at < expires_at)");
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
