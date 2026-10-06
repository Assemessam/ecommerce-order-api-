<?php

return [
    'currency' => env('CATALOGUE_CURRENCY', 'USD'),
    'cache' => [
        'enabled' => env('CATALOGUE_CACHE_ENABLED', true),
        'store' => env('CATALOGUE_CACHE_STORE', 'catalogue'),
        'namespace' => env('CATALOGUE_CACHE_NAMESPACE', 'ecommerce:catalogue:'.env('APP_ENV', 'production')),
        'ttl' => max(1, min(300, (int) env('CATALOGUE_CACHE_TTL', 45))),
    ],
];
