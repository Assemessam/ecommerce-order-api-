<?php

return [
    'connection' => 'rate-limits',
    'policies' => [
        'auth-register' => ['attempts' => max(1, (int) env('RATE_LIMIT_REGISTER_PER_HOUR', 5)), 'minutes' => 60],
        'catalogue-read' => ['attempts' => max(1, (int) env('RATE_LIMIT_CATALOGUE_PER_MINUTE', 120)), 'minutes' => 1],
        'health-read' => ['attempts' => max(1, (int) env('RATE_LIMIT_HEALTH_PER_MINUTE', 120)), 'minutes' => 1],
        'cart-read' => ['attempts' => max(1, (int) env('RATE_LIMIT_CART_READ_PER_MINUTE', 120)), 'minutes' => 1],
        'cart-mutation' => ['attempts' => max(1, (int) env('RATE_LIMIT_CART_MUTATION_PER_MINUTE', 60)), 'minutes' => 1],
        'promotion-mutation' => ['attempts' => max(1, (int) env('RATE_LIMIT_PROMOTION_PER_MINUTE', 30)), 'minutes' => 1],
        'checkout' => ['attempts' => max(1, (int) env('RATE_LIMIT_CHECKOUT_PER_MINUTE', 20)), 'minutes' => 1],
        'order-read' => ['attempts' => max(1, (int) env('RATE_LIMIT_ORDER_READ_PER_MINUTE', 120)), 'minutes' => 1],
        'order-cancel' => ['attempts' => max(1, (int) env('RATE_LIMIT_ORDER_CANCEL_PER_MINUTE', 20)), 'minutes' => 1],
        'admin-read' => ['attempts' => max(1, (int) env('RATE_LIMIT_ADMIN_READ_PER_MINUTE', 120)), 'minutes' => 1],
        'admin-mutation' => ['attempts' => max(1, (int) env('RATE_LIMIT_ADMIN_MUTATION_PER_MINUTE', 30)), 'minutes' => 1],
        'account-read' => ['attempts' => max(1, (int) env('RATE_LIMIT_ACCOUNT_READ_PER_MINUTE', 120)), 'minutes' => 1],
        'account-mutation' => ['attempts' => max(1, (int) env('RATE_LIMIT_ACCOUNT_MUTATION_PER_MINUTE', 30)), 'minutes' => 1],
    ],
    'login' => [
        'ip_per_minute' => max(1, (int) env('RATE_LIMIT_LOGIN_IP_PER_MINUTE', 30)),
        'account_ip_per_minute' => max(1, (int) env('RATE_LIMIT_LOGIN_ACCOUNT_IP_PER_MINUTE', 5)),
        'account_per_minute' => max(1, (int) env('RATE_LIMIT_LOGIN_ACCOUNT_PER_MINUTE', 20)),
    ],
];
