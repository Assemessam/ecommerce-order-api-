<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->foreignId('promotion_id')->nullable()->constrained()->nullOnDelete();
            $table->index('promotion_id');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropForeign(['promotion_id']);
            $table->dropIndex(['promotion_id']);
            $table->dropColumn('promotion_id');
        });
    }
};
