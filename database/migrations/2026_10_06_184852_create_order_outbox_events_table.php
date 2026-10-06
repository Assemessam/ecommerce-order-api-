<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 32);
            $table->timestampTz('occurred_at', 6);
            $table->string('status', 16)->default('pending');
            $table->uuid('dispatch_token')->nullable();
            $table->unsignedInteger('dispatch_attempts')->default(0);
            $table->unsignedInteger('processing_attempts')->default(0);
            $table->timestampTz('available_at', 6);
            $table->timestampTz('last_dispatched_at', 6)->nullable();
            $table->timestampTz('processed_at', 6)->nullable();
            $table->timestampTz('failed_at', 6)->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestampsTz(6);
            $table->unique(['order_id', 'event_type']);
        });
        DB::statement("ALTER TABLE order_outbox_events ADD CONSTRAINT order_outbox_type_valid CHECK (event_type IN ('order.placed', 'order.cancelled'))");
        DB::statement("ALTER TABLE order_outbox_events ADD CONSTRAINT order_outbox_state_valid CHECK (status IN ('pending', 'queued', 'processed', 'failed') AND (status = 'pending') = (dispatch_token IS NULL) AND (status = 'processed') = (processed_at IS NOT NULL) AND (status = 'failed') = (failed_at IS NOT NULL))");
        DB::statement('ALTER TABLE order_outbox_events ADD CONSTRAINT order_outbox_attempts_valid CHECK (dispatch_attempts >= 0 AND processing_attempts >= 0)');
        DB::statement("CREATE INDEX order_outbox_due_idx ON order_outbox_events (available_at, id) WHERE status IN ('pending', 'queued')");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_outbox_events');
    }
};
