<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_redemptions', function (Blueprint $table): void {
            $table->id()->generatedAs();
            $table->foreignId('promotion_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('redemption_key')->unique();
            $table->bigInteger('discount_minor');
            $table->timestampTz('redeemed_at', 6);
            $table->timestampsTz();
            $table->index(['promotion_id', 'user_id']);
            $table->index('user_id');
        });

        DB::statement('ALTER TABLE promotion_redemptions ADD CONSTRAINT promotion_redemptions_discount_non_negative CHECK (discount_minor >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_redemptions');
    }
};
