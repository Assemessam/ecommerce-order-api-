<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('event_id')->unique()->constrained('order_outbox_events')->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 32);
            $table->timestampTz('occurred_at', 6);
            $table->timestampsTz(6);
            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notifications');
    }
};
