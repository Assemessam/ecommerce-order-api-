<?php

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;

return [
    'api_path' => 'api',
    'export_path' => 'docs/openapi.json',
    'info' => [
        'version' => '1.0.0',
        'description' => 'E-commerce orders, carts, products and promotions. Money is represented as integer minor units. '
            .'Register or log in to obtain a Sanctum token, then send Authorization: Bearer <token>. '
            .'Administrative permissions are provisioned through local Artisan commands; there is no public role assignment endpoint.',
    ],
    'ui' => ['title' => 'E-Commerce Order & Promotion API'],
    'dev_tools' => ['enabled' => false],
    'security_strategy' => MiddlewareAuthSecurityStrategy::class,
    'middleware' => ['web', RestrictedDocsAccess::class],
];
