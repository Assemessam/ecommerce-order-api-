<?php

return [
    'connection' => env('ORDER_EVENTS_QUEUE_CONNECTION', 'order-redis'),
    'queue' => env('ORDER_EVENTS_QUEUE', 'order-events-'.env('APP_ENV', 'production')),
    'lease_seconds' => max(180, (int) env('ORDER_EVENTS_LEASE_SECONDS', 300)),
    'dispatch_retry_seconds' => max(1, (int) env('ORDER_EVENTS_DISPATCH_RETRY_SECONDS', 15)),
    'batch_size' => max(1, min(1000, (int) env('ORDER_EVENTS_BATCH_SIZE', 100))),
];
