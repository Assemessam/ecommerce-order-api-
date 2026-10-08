<?php

namespace App\Http;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\Tag;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Illuminate\Foundation\Http\FormRequest;

/** Enrich inferred request schemas without maintaining a parallel specification. */
class OpenApiDocumentTransformer implements DocumentTransformer
{
    /** @var array<string, list<array<string, mixed>>> */
    private const EXAMPLES = [
        'RegisterRequest' => [['name' => 'Example Customer', 'email' => 'customer@example.test', 'password' => 'ExamplePassword123!', 'password_confirmation' => 'ExamplePassword123!', 'device_name' => 'API documentation']],
        'LoginRequest' => [['email' => 'customer@example.test', 'password' => 'ExamplePassword123!', 'device_name' => 'API documentation']],
        'AddCartItemRequest' => [['product_id' => 1, 'quantity' => 2]],
        'UpdateCartItemRequest' => [['quantity' => 3]],
        'ApplyPromotionRequest' => [['code' => 'SAVE10']],
        'ProductStoreRequest' => [['name' => 'Example Keyboard', 'sku' => 'EXAMPLE-KEYBOARD', 'description' => 'A sample product.', 'price_minor' => 12900, 'stock_quantity' => 20, 'status' => 'active']],
        'ProductUpdateRequest' => [['price_minor' => 11900, 'description' => null, 'stock_adjustment' => 5]],
        'PromotionStoreRequest' => [
            ['code' => 'EXAMPLE-PERCENT', 'type' => 'percentage', 'value' => 1000, 'minimum_cart_amount_minor' => 10000, 'maximum_discount_minor' => 5000, 'starts_at' => '2026-01-01T00:00:00Z', 'expires_at' => '2099-12-31T23:59:59Z', 'global_usage_limit' => 1000, 'per_customer_usage_limit' => 2, 'is_active' => true],
            ['code' => 'EXAMPLE-FIXED', 'type' => 'fixed', 'value' => 2500, 'minimum_cart_amount_minor' => 10000, 'maximum_discount_minor' => null, 'starts_at' => null, 'expires_at' => null, 'global_usage_limit' => null, 'per_customer_usage_limit' => 1, 'is_active' => true],
        ],
        'PromotionUpdateRequest' => [['value' => 1500, 'maximum_discount_minor' => null, 'expires_at' => '2099-12-31T23:59:59Z', 'is_active' => true]],
    ];

    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $document->info->title = 'E-Commerce Order & Promotion API';
        $document->servers = [Server::make('/api')->setDescription('Same origin as the documentation; uses the configured local host and APP_PORT.')];
        $document->tags = [];
        foreach ([
            'Health' => 'Public API health status.',
            'Authentication' => 'Customer registration, Sanctum bearer tokens and profile.',
            'Products' => 'Public active product catalogue.',
            'Cart' => 'Customer cart lines, availability and estimated totals.',
            'Cart Promotions' => 'Apply or remove a promotion on the current cart.',
            'Checkout' => 'Transactional checkout and customer-scoped idempotent replay.',
            'Orders' => 'Owned order history, immutable snapshots and cancellation.',
            'Admin Products' => 'Product management with products and inventory permissions.',
            'Admin Promotions' => 'Promotion management with promotions permissions.',
        ] as $tag => $description) {
            $document->tags[] = new Tag($tag, $description);
        }
        $this->documentRequests($document);
        $this->documentQueryParameters($document);

        foreach ($document->components->securitySchemes as $scheme) {
            $scheme->setDescription('Sanctum bearer token returned by registration or login. Enter only the token in the documentation UI; the UI adds Bearer.');
        }

        foreach (array_keys($document->components->schemas) as $name) {
            if (class_basename($name) === 'CheckoutRequest') {
                $document->components->removeSchema($name);
            }
        }
        foreach (array_keys($document->components->responses) as $name) {
            $document->components->removeResponse($name);
        }
    }

    private function documentRequests(OpenApi $document): void
    {
        foreach ($document->components->schemas as $class => $schema) {
            if (! is_a($class, FormRequest::class, true) || ! $schema->type instanceof ObjectType) {
                continue;
            }
            $name = class_basename($class);
            $body = $schema->type;
            $body->examples(self::EXAMPLES[$name] ?? []);
            /** @var FormRequest $request */
            $request = new $class;
            foreach ($request->rules() as $field => $rules) {
                if (in_array('missing', $rules, true)) {
                    unset($body->properties[$field]);

                    continue;
                }
                $property = $body->properties[$field] ?? null;
                foreach ($rules as $rule) {
                    if ($property instanceof IntegerType && is_string($rule) && preg_match('/^(min|max):(-?\d+)$/', $rule, $matches)) {
                        if ($matches[1] === 'min') {
                            $property->setMin((int) $matches[2]);
                        } else {
                            $property->setMax((int) $matches[2]);
                        }
                    }
                }
                if ($property instanceof StringType && in_array('required', $rules, true)) {
                    $property->setMin(1);
                }
                if ($property !== null && in_array('integer:strict', $rules, true)) {
                    $property->setDescription('Strict JSON integer; numeric strings and floating-point values are rejected.');
                }
            }
            $this->describeRequest($name, $body);
        }
    }

    private function describeRequest(string $name, ObjectType $body): void
    {
        if (in_array($name, ['LoginRequest', 'RegisterRequest'], true)) {
            $body->properties['password']->setMax(72)->setDescription('At most 72 UTF-8 bytes, no null bytes. '.($name === 'RegisterRequest' ? 'At least 12 characters, including upper/lowercase letters, a number and a symbol.' : 'The existing account password.'));
            if ($name === 'RegisterRequest') {
                $body->properties['password']->setMin(12);
                $body->properties['password_confirmation']->setDescription('Must equal password.');
            }
            $body->properties['email']->setDescription('Trimmed and lowercased before validation.');
        }
        if (in_array($name, ['ProductStoreRequest', 'ProductUpdateRequest'], true)) {
            $body->properties['price_minor']->setDescription('Nonnegative strict JSON integer in minor units (12900 = 129.00).');
            $body->properties['description']->setDescription('Nullable text; explicit null clears the description. Null bytes are rejected.');
            foreach (['name', 'sku'] as $field) {
                $body->properties[$field]->setDescription('Trimmed nonempty string; null bytes are rejected.'.($field === 'sku' ? ' Must be unique.' : ''));
            }
            if ($name === 'ProductStoreRequest') {
                $body->properties['stock_quantity']->default(0)->setDescription('Initial absolute inventory. Strict JSON integer. stock_adjustment must be absent.');
                $body->properties['status']->default('active');
            } else {
                /** @var IntegerType $adjustment */
                $adjustment = $body->properties['stock_adjustment'];
                $body->properties['stock_adjustment'] = (new AnyOf)->setItems([
                    (clone $adjustment)->setMax(-1), (clone $adjustment)->setMin(1),
                ])->setDescription('Signed relative inventory change, not an absolute replacement. Zero is rejected. Requires inventory.adjust. stock_quantity must be absent.');
                $body->setDescription('Omitted fields are preserved. Only description accepts null. stock_quantity is forbidden. Empty PATCH is allowed.');
            }
        }
        if (in_array($name, ['PromotionStoreRequest', 'PromotionUpdateRequest'], true)) {
            $body->properties['value']->setDescription('Strict JSON integer. percentage: 1..10000 basis points (1000 = 10%); fixed: positive minor units up to PHP_INT_MAX. PATCH checks the retained type/value too.');
            foreach (['starts_at', 'expires_at'] as $field) {
                $body->properties[$field]->format('date-time')->pattern('^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{6})?(Z|[+-]\d{2}:\d{2})$')
                    ->setDescription('ISO 8601 with timezone, seconds and optional six-digit microseconds. Null clears the date. Expiration must be strictly after start, including retained PATCH dates.');
            }
            foreach (['maximum_discount_minor', 'global_usage_limit', 'per_customer_usage_limit'] as $field) {
                $body->properties[$field]->setDescription('Positive strict JSON integer or null. Null removes the cap/limit; omission preserves it on PATCH. Usage limits cannot be reduced below consumed usage.');
            }
            $body->properties['is_active']->setDescription('Strict JSON boolean; strings and integers are rejected.');
            if ($name === 'PromotionStoreRequest') {
                $body->properties['minimum_cart_amount_minor']->default(0);
                $body->properties['is_active']->default(true);
            } else {
                $body->setDescription('Omitted fields are preserved; only dates, discount cap and usage limits accept null. Empty PATCH is allowed.');
            }
        }
        if (isset($body->properties['code'])) {
            $body->properties['code']->setDescription('Trimmed and uppercased. One initial alphanumeric character followed by alphanumerics, underscores or hyphens; maximum 64 characters.');
        }
        if ($name === 'AddCartItemRequest') {
            $body->properties['product_id']->setDescription('Active product ID; strict positive JSON integer.');
        }
        if (isset($body->properties['quantity'])) {
            $body->properties['quantity']->setDescription('Strict positive JSON integer bounded by current stock. Adding to an existing line increments its quantity; PATCH replaces it.');
        }
    }

    private function documentQueryParameters(OpenApi $document): void
    {
        foreach ($document->paths as $path) {
            foreach ($path->operations as $operation) {
                foreach ($operation->parameters as $parameter) {
                    if (! $parameter instanceof Parameter || $parameter->in !== 'query') {
                        continue;
                    }
                    $type = $parameter->schema?->type;
                    if ($type instanceof IntegerType && in_array($parameter->name, ['min_price', 'max_price'], true)) {
                        $type->setMax(PHP_INT_MAX);
                    }
                    $parameter->description(match ($parameter->name) {
                        'search' => 'Literal case-insensitive name/SKU/description search. Trimmed; blank becomes null. Null bytes are rejected.',
                        'min_price' => 'Inclusive minimum price in integer minor units.',
                        'max_price' => 'Inclusive maximum price in integer minor units; must be at least min_price.',
                        'available' => 'Accepts true, false, 1 or 0; true filters to positive stock, false filters to zero stock.',
                        'is_active' => 'Accepts true, false, 1 or 0.',
                        'status' => 'Filter administrative products by status.',
                        'sort' => 'Sort field; price sorts the minor-unit price.',
                        'direction' => 'Sort direction.',
                        'page' => 'Page number, starting at 1.',
                        'per_page' => 'Items per page, from 1 to 100.',
                        default => $parameter->description,
                    });
                    if (in_array($parameter->name, ['page', 'per_page', 'sort', 'direction'], true)) {
                        $type?->default(match ($parameter->name) {
                            'page' => 1, 'per_page' => 15, 'sort' => 'created_at', 'direction' => 'desc',
                        });
                    }
                }
            }
        }
    }
}
