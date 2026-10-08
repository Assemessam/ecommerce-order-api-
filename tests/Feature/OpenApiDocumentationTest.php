<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Dedoc\Scramble\Generator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Routes;

uses(LazilyRefreshDatabase::class);

afterEach(function (): void {
    app()->instance('env', 'testing');
});

/** @return array<string, mixed> */
function documentationContract(): array
{
    test()->assertFileExists(base_path('docs/openapi.json'));

    return json_decode(file_get_contents(base_path('docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
}

/** @param array<string, mixed> $document
 * @return array<string, array<string, mixed>>
 */
function documentationOperations(array $document): array
{
    $operations = [];

    foreach ($document['paths'] as $path => $pathItem) {
        foreach ($pathItem as $method => $operation) {
            if (in_array($method, ['get', 'post', 'patch', 'delete', 'put', 'head', 'options', 'trace'], true)) {
                $operations[strtoupper($method).' /api'.$path] = $operation;
            }
        }
    }

    ksort($operations);

    return $operations;
}

/** @param array<string, mixed> $document
 * @return array<string, mixed>
 */
function documentationResolve(array $document, string $reference): array
{
    expect($reference)->toStartWith('#/');
    $value = $document;

    foreach (explode('/', substr($reference, 2)) as $segment) {
        $key = str_replace(['~1', '~0'], ['/', '~'], $segment);
        expect($value)->toHaveKey($key);
        $value = $value[$key];
    }

    expect($value)->toBeArray()->not->toBeEmpty();

    return $value;
}

/** @param array<string, mixed> $schema
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function documentationDereference(array $schema, array $document): array
{
    return isset($schema['$ref'])
        ? documentationResolve($document, $schema['$ref'])
        : $schema;
}

/**
 * @param  array<string, mixed>  $content
 * @param  array<string, mixed>  $document
 * @return list<array{value: mixed}>
 */
function documentationExamples(array $content, array $document): array
{
    $schema = documentationDereference($content['schema'] ?? [], $document);

    return array_map(fn (mixed $value): array => ['value' => $value], $schema['examples'] ?? []);
}

/** @param array<string, mixed> $schema
 * @param  array<string, mixed>  $document
 * @return list<string>
 */
function documentationSchemaIssues(array $schema, array $document, string $location): array
{
    if (isset($schema['$ref'])) {
        documentationResolve($document, $schema['$ref']);

        return [];
    }

    $issues = [];
    $types = (array) ($schema['type'] ?? []);
    $alternatives = array_intersect(['allOf', 'anyOf', 'oneOf'], array_keys($schema));

    if ($types === [] && $alternatives === []) {
        $issues[] = "$location has no type, reference, or composition";
    }

    foreach ($types as $type) {
        if (! in_array($type, ['object', 'array', 'integer', 'number', 'boolean', 'string', 'null'], true)) {
            $issues[] = "$location has invalid type $type";
        }
    }

    foreach ($alternatives as $keyword) {
        if ($schema[$keyword] === []) {
            $issues[] = "$location.$keyword has no alternatives";
        }

        foreach ($schema[$keyword] as $index => $child) {
            $issues = [...$issues, ...documentationSchemaIssues($child, $document, "$location.$keyword.$index")];
        }
    }

    foreach ($schema['properties'] ?? [] as $name => $child) {
        $issues = [...$issues, ...documentationSchemaIssues($child, $document, "$location.$name")];
    }

    foreach ($schema['required'] ?? [] as $name) {
        if (! array_key_exists($name, $schema['properties'] ?? [])) {
            $issues[] = "$location requires undefined property $name";
        }
    }

    if (in_array('array', $types, true)) {
        if (! isset($schema['items'])) {
            $issues[] = "$location has no array item schema";
        } else {
            $issues = [...$issues, ...documentationSchemaIssues($schema['items'], $document, "$location.items")];
        }
    }

    if (is_array($schema['additionalProperties'] ?? null)) {
        $issues = [...$issues, ...documentationSchemaIssues($schema['additionalProperties'], $document, "$location.additionalProperties")];
    }

    return $issues;
}

/** @param array<string, mixed> $node
 * @param  array<string, mixed>  $document
 * @return list<string>
 */
function documentationDocumentIssues(array $node, array $document, string $location = 'document'): array
{
    $issues = [];

    if (isset($node['$ref'])) {
        documentationResolve($document, $node['$ref']);
    }

    foreach ($node as $key => $value) {
        if (! is_array($value)) {
            continue;
        }

        if ($key === 'schema' || $location === 'document.components.schemas') {
            $issues = [...$issues, ...documentationSchemaIssues($value, $document, "$location.$key")];
        }

        $issues = [...$issues, ...documentationDocumentIssues($value, $document, "$location.$key")];
    }

    return $issues;
}

/**
 * Check the JSON Schema features used by this API's generated contracts.
 *
 * @param  array<string, mixed>  $schema
 * @param  array<string, mixed>  $document
 * @return list<string>
 */
function documentationValueIssues(mixed $value, array $schema, array $document, string $location = 'response'): array
{
    $schema = documentationDereference($schema, $document);
    $issues = [];

    foreach ($schema['allOf'] ?? [] as $alternative) {
        $issues = [...$issues, ...documentationValueIssues($value, $alternative, $document, $location)];
    }

    foreach (['anyOf', 'oneOf'] as $keyword) {
        if (! isset($schema[$keyword])) {
            continue;
        }

        $matches = array_filter($schema[$keyword], fn (array $alternative): bool => documentationValueIssues($value, $alternative, $document, $location) === []);

        if (count($matches) === 0 || ($keyword === 'oneOf' && count($matches) !== 1)) {
            $issues[] = "$location does not match $keyword";
        }
    }

    $actualType = match (true) {
        $value === null => 'null',
        is_int($value) => 'integer',
        is_float($value) => 'number',
        is_bool($value) => 'boolean',
        is_string($value) => 'string',
        is_array($value) => 'array',
        $value instanceof stdClass => 'object',
        default => 'unknown',
    };
    $types = (array) ($schema['type'] ?? []);

    if ($types !== [] && ! in_array($actualType, $types, true)
        && ! ($actualType === 'integer' && in_array('number', $types, true))) {
        return [...$issues, "$location is $actualType, expected ".implode('|', $types)];
    }

    if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
        $issues[] = "$location is outside its enum";
    }

    if (array_key_exists('const', $schema) && $value !== $schema['const']) {
        $issues[] = "$location differs from its constant";
    }

    if ($value instanceof stdClass) {
        $properties = get_object_vars($value);

        foreach ($schema['required'] ?? [] as $name) {
            if (! array_key_exists($name, $properties)) {
                $issues[] = "$location.$name is required";
            }
        }

        foreach ($properties as $name => $child) {
            if (isset($schema['properties'][$name])) {
                $issues = [...$issues, ...documentationValueIssues($child, $schema['properties'][$name], $document, "$location.$name")];
            } elseif (($schema['additionalProperties'] ?? true) === false) {
                $issues[] = "$location.$name is undocumented";
            } elseif (is_array($schema['additionalProperties'] ?? null)) {
                $issues = [...$issues, ...documentationValueIssues($child, $schema['additionalProperties'], $document, "$location.$name")];
            }
        }
    }

    if (is_array($value) && isset($schema['items'])) {
        foreach ($value as $index => $child) {
            $issues = [...$issues, ...documentationValueIssues($child, $schema['items'], $document, "$location.$index")];
        }
    }

    if (is_int($value) || is_float($value)) {
        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            $issues[] = "$location is below its minimum";
        }

        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            $issues[] = "$location exceeds its maximum";
        }
    }

    if (is_string($value)) {
        if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
            $issues[] = "$location exceeds its maximum length";
        }

        if (isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
            $issues[] = "$location is below its minimum length";
        }

        if (($schema['format'] ?? null) === 'date-time' && ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            $issues[] = "$location is not an RFC 3339 timestamp";
        }

        if (($schema['format'] ?? null) === 'email' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $issues[] = "$location is not an email address";
        }

        if (isset($schema['pattern']) && ! preg_match('~'.str_replace('~', '\\~', $schema['pattern']).'~u', $value)) {
            $issues[] = "$location does not match its pattern";
        }
    }

    return $issues;
}

/** @param list<array<string, mixed>> $items
 * @return list<string>
 */
function documentationPostmanOperations(array $items): array
{
    $operations = [];

    foreach ($items as $item) {
        if (isset($item['request'])) {
            $request = $item['request'];
            $segments = array_map(
                fn (string $segment): string => preg_match('/^(?:\{\{[^}]+\}\}|\d+)$/', $segment) ? '{id}' : $segment,
                $request['url']['path'],
            );
            $operations[] = $request['method'].' /'.implode('/', $segments);
        }

        $operations = [...$operations, ...documentationPostmanOperations($item['item'] ?? [])];
    }

    return $operations;
}

it('serves the interactive UI and the exported JSON contract in local development', function (): void {
    app()->instance('env', 'local');

    $this->get('/docs/api')->assertOk()->assertSee('elements-api', false);
    $this->getJson('/docs/api.json')->assertOk()->assertExactJson(documentationContract());
});

it('returns 403 for documentation outside local development regardless of debug mode', function (string $environment, bool $debug): void {
    app()->instance('env', $environment);
    config(['app.debug' => $debug]);

    $this->get('/docs/api')->assertForbidden();
    $this->getJson('/docs/api.json')->assertForbidden();
})->with(['testing', 'staging', 'production'])->with(['debug disabled' => false, 'debug enabled' => true]);

it('returns 403 for an authenticated administrator outside local development', function (string $environment): void {
    $administrator = User::factory()->administrator()->create();
    $this->actingAs($administrator);
    app()->instance('env', $environment);
    config(['app.debug' => true]);

    $this->get('/docs/api')->assertForbidden();
    $this->getJson('/docs/api.json')->assertForbidden();
})->with(['testing', 'staging', 'production']);

it('documents exactly the 25 runtime and Postman API operations', function (): void {
    $document = documentationContract();
    $documented = array_keys(documentationOperations($document));
    $runtime = collect(Routes::getRoutes())->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/'))
        ->flatMap(fn (Route $route): array => array_map(
            fn (string $method): string => $method.' /'.$route->uri(),
            array_values(array_diff($route->methods(), ['HEAD'])),
        ))->sort()->values()->all();
    $collection = json_decode(file_get_contents(base_path('postman/Ecommerce_Order_Promotion_API.postman_collection.json')), true, flags: JSON_THROW_ON_ERROR);
    $postman = array_values(array_unique(documentationPostmanOperations($collection['item'])));
    sort($postman);

    expect(array_column($document['servers'], 'url'))->toBe(['/api']);
    expect($documented)->toHaveCount(25)->toBe($runtime)->toBe($postman);
});

it('exports a structurally valid OpenAPI 3.1 contract with resolved typed schemas', function (): void {
    $document = documentationContract();

    expect($document['openapi'])->toMatch('/^3\.1\.\d+$/');
    expect($document['info']['title'])->toBeString()->not->toBeEmpty();
    expect($document['info']['version'])->toBeString()->not->toBeEmpty();
    expect($document['info']['description'])->toBeString()->not->toBeEmpty();
    expect(documentationDocumentIssues($document, $document))->toBe([]);
    $operationIds = [];

    foreach (documentationOperations($document) as $operation) {
        expect($operation['summary'])->toBeString()->not->toBeEmpty();
        expect($operation['description'])->toBeString()->not->toBeEmpty();
        expect($operation['tags'])->toHaveCount(1);
        expect($operation['responses'])->toBeArray()->not->toBeEmpty();
        $operationIds[] = $operation['operationId'];

        foreach ($operation['responses'] as $status => $response) {
            expect((string) $status)->toMatch('/^[1-5][0-9]{2}$/');
            expect($response['description'])->toBeString()->not->toBeEmpty();
        }
    }

    expect($operationIds)->toHaveCount(count(array_unique($operationIds)));
});

it('documents Sanctum bearer security only for middleware-protected routes', function (): void {
    $document = documentationContract();
    $schemes = $document['components']['securitySchemes'];
    expect($schemes)->toHaveCount(1);
    $schemeName = array_key_first($schemes);
    expect($schemes[$schemeName]['type'])->toBe('http');
    expect($schemes[$schemeName]['scheme'])->toBe('bearer');
    $operations = documentationOperations($document);

    foreach (Routes::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/')) {
            continue;
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $operation = $operations[$method.' /'.$route->uri()];
            $security = $operation['security'] ?? $document['security'] ?? [];
            $authenticated = in_array('auth:sanctum', $route->gatherMiddleware(), true);
            expect($security)->toBe($authenticated ? [[$schemeName => []]] : []);
        }
    }
});

it('documents exactly the JSON body operations and keeps other operations bodyless', function (): void {
    $document = documentationContract();
    $expectedBodies = [
        'POST /api/auth/register', 'POST /api/auth/login', 'POST /api/cart/items',
        'PATCH /api/cart/items/{id}', 'POST /api/cart/promotion',
        'POST /api/admin/products', 'PATCH /api/admin/products/{id}',
        'POST /api/admin/promotions', 'PATCH /api/admin/promotions/{id}',
    ];
    $actualBodies = [];

    foreach (documentationOperations($document) as $name => $operation) {
        if (! isset($operation['requestBody'])) {
            continue;
        }

        $actualBodies[] = $name;
        $content = $operation['requestBody']['content']['application/json'];
        expect($content)->toHaveKey('schema');
        $examples = documentationExamples($content, $document);
        expect($examples)->not->toBeEmpty();

        foreach ($examples as $example) {
            $value = json_decode(json_encode($example['value'], JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
            expect(documentationValueIssues($value, $content['schema'], $document, $name))->toBe([]);
        }
    }

    sort($expectedBodies);
    expect($actualBodies)->toBe($expectedBodies);
    $header = collect($document['paths']['/checkout']['post']['parameters'])->firstWhere('name', 'Idempotency-Key');
    expect($header['in'])->toBe('header');
    expect($header['required'] ?? false)->toBeFalse();
    expect($header['schema']['type'])->toBe('string');
    expect($header['schema']['maxLength'])->toBe(128);
});

it('preserves creation versus PATCH stock fields and nullable promotion request contracts', function (): void {
    $document = documentationContract();
    $store = documentationDereference($document['paths']['/admin/products']['post']['requestBody']['content']['application/json']['schema'], $document);
    $update = documentationDereference($document['paths']['/admin/products/{id}']['patch']['requestBody']['content']['application/json']['schema'], $document);
    expect($store['required'])->toEqualCanonicalizing(['name', 'sku', 'price_minor']);
    expect($store['properties'])->toHaveKey('stock_quantity')->not->toHaveKey('stock_adjustment');
    expect($update['required'] ?? [])->toBe([]);
    expect($update['properties'])->toHaveKey('stock_adjustment')->not->toHaveKey('stock_quantity');
    foreach ([-PHP_INT_MAX, -1, 1, PHP_INT_MAX] as $adjustment) {
        expect(documentationValueIssues($adjustment, $update['properties']['stock_adjustment'], $document))->toBe([]);
    }
    foreach ([PHP_INT_MIN, 0, '1', 1.5, null] as $adjustment) {
        expect(documentationValueIssues($adjustment, $update['properties']['stock_adjustment'], $document))->not->toBeEmpty();
    }
    expect(documentationDereference($update['properties']['status'], $document)['enum'])->toEqualCanonicalizing(['active', 'inactive']);
    $promotion = documentationDereference($document['paths']['/admin/promotions']['post']['requestBody']['content']['application/json']['schema'], $document);
    expect($promotion['required'])->toEqualCanonicalizing(['code', 'type', 'value']);
    expect(documentationDereference($promotion['properties']['type'], $document)['enum'])->toEqualCanonicalizing(['percentage', 'fixed']);
    expect($promotion['properties']['value']['type'])->toBe('integer');
    expect($promotion['properties']['value']['description'])->toContain('1000');

    foreach (['maximum_discount_minor', 'global_usage_limit', 'per_customer_usage_limit', 'starts_at', 'expires_at'] as $field) {
        expect(documentationValueIssues(null, $promotion['properties'][$field], $document, $field))->toBe([]);
    }

    $examples = documentationExamples($document['paths']['/admin/promotions']['post']['requestBody']['content']['application/json'], $document);
    expect(array_column(array_column($examples, 'value'), 'type'))->toContain('percentage', 'fixed');
});

it('documents integer path IDs and actual query parameters for paginated reads', function (): void {
    $document = documentationContract();

    foreach ($document['paths'] as $path => $pathItem) {
        if (! str_contains($path, '{id}')) {
            continue;
        }

        foreach ($pathItem as $operation) {
            $id = collect($operation['parameters'] ?? [])->firstWhere('name', 'id');
            expect($id['in'])->toBe('path');
            expect($id['required'])->toBeTrue();
            expect($id['schema']['type'])->toBe('integer');
        }
    }

    foreach ([
        '/products' => ['search', 'min_price', 'max_price', 'available', 'sort', 'direction', 'page', 'per_page'],
        '/admin/products' => ['search', 'min_price', 'max_price', 'available', 'sort', 'direction', 'page', 'per_page', 'status'],
        '/admin/promotions' => ['page', 'per_page', 'is_active'],
        '/orders' => ['page', 'per_page'],
    ] as $path => $names) {
        $parameters = collect($document['paths'][$path]['get']['parameters'])->where('in', 'query')->keyBy('name');
        expect($parameters->keys()->all())->toEqualCanonicalizing($names);
        expect($parameters['page']['schema']['type'])->toBe('integer');
        expect($parameters['per_page']['schema']['maximum'])->toBe(100);
    }
});

it('documents success statuses, bodyless 204 responses and applicable failure envelopes', function (): void {
    $document = documentationContract();

    foreach (documentationOperations($document) as $name => $operation) {
        $success = match ($name) {
            'POST /api/auth/register', 'POST /api/cart/items', 'POST /api/admin/products', 'POST /api/admin/promotions', 'POST /api/checkout' => 201,
            'POST /api/auth/logout', 'DELETE /api/cart/items/{id}', 'DELETE /api/cart/promotion' => 204,
            default => 200,
        };
        expect($operation['responses'])->toHaveKeys([$success, 429, 503]);
        expect($operation['responses'][429]['headers'])->toHaveKey('Retry-After');
        expect($operation['responses'][503]['headers'] ?? [])->not->toHaveKey('Retry-After');

        if ($success === 204) {
            expect($operation['responses'][204])->not->toHaveKey('content');
        } else {
            expect($operation['responses'][$success]['content'])->toHaveKey('application/json');
        }

        if (str_contains($name, ' /api/admin/')) {
            expect($operation['responses'])->toHaveKeys([401, 403]);
            expect($operation['description'])->toContain('Requires', 'administrator');
        }

        foreach ($operation['responses'] as $status => $response) {
            if ($status < 400) {
                continue;
            }

            $schema = documentationDereference($response['content']['application/json']['schema'], $document);
            $error = documentationDereference($schema['properties']['error'], $document);
            expect($error['properties'])->toHaveKeys(['code', 'message', 'request_id']);

            foreach (['code', 'message', 'request_id'] as $field) {
                expect($error['properties'][$field]['type'])->toBe('string');
            }
        }
    }

    expect($document['paths']['/checkout']['post']['responses'])->toHaveKeys([200, 201, 401, 409, 422, 429, 503]);
    expect($document['paths']['/orders/{id}/cancel']['post']['responses'])->toHaveKeys([200, 401, 404, 409]);
    expect($document['paths']['/cart/promotion']['post']['responses'])->toHaveKeys([200, 401, 404, 409, 422]);
});

it('matches real resources including integer money, nullable fields, arrays and pagination', function (): void {
    $document = documentationContract();
    $customer = User::factory()->create();
    $product = Product::factory()->create(['description' => null]);
    $order = Order::factory()->for($customer)->create();
    OrderItem::factory()->for($order)->for($product)->create();
    $promotion = Promotion::factory()->create();
    $administrator = User::factory()->administrator()->create();
    $responses = [
        ['GET /api/health', $this->getJson('/api/health')->assertOk()],
        ['GET /api/products', $this->getJson('/api/products')->assertOk()],
        ['GET /api/products/{id}', $this->getJson('/api/products/'.$product->id)->assertOk()],
    ];
    $this->actingAs($customer, 'sanctum');
    $responses = [...$responses,
        ['GET /api/auth/me', $this->getJson('/api/auth/me')->assertOk()],
        ['GET /api/cart', $this->getJson('/api/cart')->assertOk()],
        ['POST /api/cart/items', $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertCreated()],
        ['GET /api/orders', $this->getJson('/api/orders')->assertOk()],
        ['GET /api/orders/{id}', $this->getJson('/api/orders/'.$order->id)->assertOk()],
    ];
    $this->actingAs($administrator, 'sanctum');
    $responses = [...$responses,
        ['GET /api/admin/products', $this->getJson('/api/admin/products')->assertOk()],
        ['GET /api/admin/promotions', $this->getJson('/api/admin/promotions')->assertOk()],
        ['GET /api/admin/promotions/{id}', $this->getJson('/api/admin/promotions/'.$promotion->id)->assertOk()],
    ];

    foreach ($responses as [$name, $response]) {
        $schema = documentationOperations($document)[$name]['responses'][$response->status()]['content']['application/json']['schema'];
        $value = json_decode($response->getContent(), flags: JSON_THROW_ON_ERROR);
        expect(documentationValueIssues($value, $schema, $document, $name))->toBe([]);
    }
});

it('matches actual authentication and validation error envelopes', function (): void {
    $document = documentationContract();
    $responses = [
        ['GET /api/auth/me', $this->getJson('/api/auth/me')->assertUnauthorized()],
        ['POST /api/auth/login', $this->postJson('/api/auth/login')->assertUnprocessable()],
        ['GET /api/products/{id}', $this->getJson('/api/products/0')->assertNotFound()],
    ];

    foreach ($responses as [$name, $response]) {
        $schema = documentationOperations($document)[$name]['responses'][$response->status()]['content']['application/json']['schema'];
        $value = json_decode($response->getContent(), flags: JSON_THROW_ON_ERROR);
        expect(documentationValueIssues($value, $schema, $document, $name))->toBe([]);
    }
});

it('keeps the committed export equal to deterministic generator output', function (): void {
    $document = documentationContract();
    $generator = app(Generator::class);

    $generated = json_decode(json_encode($generator(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $regenerated = json_decode(json_encode($generator(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    expect($generated)->toBe($document);
    expect($regenerated)->toBe($document);
});

it('keeps example identities safe and excludes tokens and private infrastructure', function (): void {
    $document = documentationContract();
    $serialized = json_encode($document, JSON_THROW_ON_ERROR);

    expect($serialized)->not->toMatch('/(?:Bearer\s+[A-Za-z0-9._-]{20,}|\d+\|[A-Za-z0-9]{30,}|postgres(?:ql)?:\/\/|redis:\/\/|\/var\/www\/|\/home\/|ecommerce_order_api_test)/');

    foreach (documentationOperations($document) as $operation) {
        $content = $operation['requestBody']['content']['application/json'] ?? [];
        $examples = documentationExamples($content, $document);

        foreach ($examples as $example) {
            if (isset($example['value']['email'])) {
                expect($example['value']['email'])->toEndWith('@example.test');
            }

            expect($example['value'])->not->toHaveKeys(['token', 'access_token', 'admin_token', 'customer_token']);
        }
    }
});
