<?php

use App\Models\OrderNotification;
use App\Models\OrderOutboxEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

it('enforces one business event per order and event type in PostgreSQL', function () {
    $event = OrderOutboxEvent::factory()->create();
    expect(fn () => DB::transaction(fn () => OrderOutboxEvent::factory()->create(['order_id' => $event->order_id])))
        ->toThrow(QueryException::class);
    $this->assertDatabaseCount('order_outbox_events', 1);
});

it('enforces one durable internal result per event in PostgreSQL', function () {
    $notification = OrderNotification::factory()->create();
    expect(fn () => DB::transaction(fn () => OrderNotification::factory()->create([
        'event_id' => $notification->event_id, 'order_id' => $notification->order_id,
    ])))->toThrow(QueryException::class);
    $this->assertDatabaseCount('order_notifications', 1);
});

it('rejects inconsistent ownership completion markers and invalid event types', function (array $attributes) {
    $event = OrderOutboxEvent::factory()->create();
    expect(fn () => DB::transaction(fn () => DB::table('order_outbox_events')->where('id', $event->id)->update($attributes)))
        ->toThrow(QueryException::class);
    $this->assertDatabaseCount('order_outbox_events', 1);
})->with([
    'queued without ownership' => [['status' => 'queued']],
    'pending with ownership' => [['dispatch_token' => 'cc8f0aa7-a12b-4416-bbbd-5ba68f406512']],
    'processed without marker' => [['status' => 'processed', 'dispatch_token' => 'cc8f0aa7-a12b-4416-bbbd-5ba68f406512']],
    'failed without marker' => [['status' => 'failed', 'dispatch_token' => 'cc8f0aa7-a12b-4416-bbbd-5ba68f406512']],
    'unsupported event' => [['event_type' => 'order.shipped']],
    'negative attempts' => [['processing_attempts' => -1]],
]);
