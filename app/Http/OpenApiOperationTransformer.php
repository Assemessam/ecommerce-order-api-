<?php

namespace App\Http;

use App\Http\Resources\OrderResource;
use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\Generator\TypeTransformer;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Foundation\Http\FormRequest;

/** Correct HTTP details Scramble cannot infer across service/repository boundaries. */
class OpenApiOperationTransformer implements OperationTransformer
{
    /** @var array<string, array{string, string, string, list<int>}> */
    private const OPERATIONS = [
        'health' => ['Health', 'Check API health', 'Returns API status; this is not a dependency readiness probe.', [400]],
        'auth.register' => ['Authentication', 'Register a customer', 'Creates a customer and returns a Sanctum bearer token. Email is trimmed and lowercased; no internal role is assigned.', [400, 422]],
        'auth.login' => ['Authentication', 'Log in', 'Returns a Sanctum bearer token for valid credentials. Email is trimmed and lowercased.', [400, 401, 422]],
        'auth.me' => ['Authentication', 'Get the current user', 'Returns the authenticated customer profile.', []],
        'auth.logout' => ['Authentication', 'Log out', 'Revokes the current bearer token. No request body or response body.', []],
        'products.index' => ['Products', 'List public products', 'Paginated active catalogue. Prices and price filters use integer minor units.', [400, 422]],
        'products.show' => ['Products', 'Get a public product', 'Inactive or nonexistent products return 404.', [400, 404]],
        'cart.show' => ['Cart', 'Get the current cart', 'Returns current prices, availability, promotion eligibility and estimated totals. An unpersisted empty cart has a null ID.', [409]],
        'cart.items.store' => ['Cart', 'Add a cart item', 'Adds quantity to an existing product line, or creates a line. Does not reserve inventory. Returns the whole cart.', [404, 409, 422]],
        'cart.items.update' => ['Cart', 'Set cart item quantity', 'Replaces the quantity of an owned cart item. The ID identifies a cart item, not a product. Returns the whole cart.', [404, 409, 422]],
        'cart.items.destroy' => ['Cart', 'Remove a cart item', 'Deletes an owned cart item. Foreign or missing items return 404. No request body or response body.', [404, 409]],
        'cart.promotion.store' => ['Cart Promotions', 'Apply a promotion', 'Normalizes the code to uppercase. Returns the cart with estimated discounts. Eligibility and usage limits are rechecked at checkout.', [404, 409, 422]],
        'cart.promotion.destroy' => ['Cart Promotions', 'Remove the cart promotion', 'Removes any applied promotion. No request body or response body.', [409]],
        'checkout' => ['Checkout', 'Check out the cart', 'Bodyless checkout. Optional Idempotency-Key scopes replays to this customer. A fresh checkout returns 201; a valid replay returns the existing order with 200. Replay is checked before empty-cart eligibility. Without a key there is no replay guarantee.', [404, 409, 422]],
        'orders.index' => ['Orders', 'List customer orders', 'Paginated history for the authenticated customer. Item snapshots are omitted from this list; request order details to retrieve them.', [422]],
        'orders.show' => ['Orders', 'Get an order', 'Returns owned order details and immutable item/promotion snapshots. Foreign and nonexistent orders both return 404.', [404]],
        'orders.cancel' => ['Orders', 'Cancel an order', 'Bodyless transition from placed to cancelled, restoring inventory once. Repeating cancellation of a cancelled order returns 200 without restoring inventory again. Other statuses or conflicting inventory changes return 409.', [404, 409]],
        'admin.products.index' => ['Admin Products', 'List administrative products', 'Includes active and inactive products. Requires products.view-admin (administrator or product_manager).', [422]],
        'admin.products.show' => ['Admin Products', 'Get an administrative product', 'Requires products.view-admin (administrator or product_manager).', [404]],
        'admin.products.store' => ['Admin Products', 'Create a product', 'Requires products.create (administrator or product_manager). stock_quantity is initial absolute inventory; defaults to 0. Status defaults to active. SKU is trimmed and unique. stock_adjustment must be absent.', [409, 422]],
        'admin.products.update' => ['Admin Products', 'Update a product', 'Requires products.update (administrator or product_manager); stock_adjustment additionally requires inventory.adjust. The adjustment is a signed, nonzero relative change. stock_quantity must be absent. Omitted fields are preserved; description: null clears it. Empty PATCH is allowed.', [404, 409, 422]],
        'admin.promotions.index' => ['Admin Promotions', 'List administrative promotions', 'Requires promotions.view-admin (administrator or promotion_manager). Includes redemption counts.', [422]],
        'admin.promotions.show' => ['Admin Promotions', 'Get an administrative promotion', 'Requires promotions.view-admin (administrator or promotion_manager).', [404]],
        'admin.promotions.store' => ['Admin Promotions', 'Create a promotion', 'Requires promotions.create (administrator or promotion_manager). Codes are trimmed, uppercased and unique. Percentage value is basis points (1000 = 10%, maximum 10000); fixed value is integer minor units. Expiration must follow start. Minimum defaults to 0 and active defaults to true.', [409, 422]],
        'admin.promotions.update' => ['Admin Promotions', 'Update a promotion', 'Requires promotions.update (administrator or promotion_manager). Omitted fields are preserved. Explicit null clears dates, discount cap and usage limits. Validates merged type/value and dates; usage limits cannot fall below consumed usage. Empty PATCH is allowed.', [404, 409, 422]],
    ];

    public function __construct(private OpenApi $openApi, private TypeTransformer $typeTransformer) {}

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $name = $routeInfo->route->getName();
        if (! isset(self::OPERATIONS[$name])) {
            return;
        }

        [$tag, $summary, $description, $errors] = self::OPERATIONS[$name];
        $operation->setTags([$tag])->summary($summary)->description($description)->setOperationId(str_replace('.', '_', $name));
        foreach ($operation->parameters as $parameter) {
            if ($parameter instanceof Parameter && $parameter->in === 'path') {
                $parameter->setSchema(Schema::fromType((new IntegerType)->setMin(1)->setMax(PHP_INT_MAX)));
                $parameter->description($tag === 'Cart' ? 'Owned cart item ID.' : 'Resource ID.');
            }
            if ($parameter instanceof Parameter && in_array($parameter->name, ['sort', 'direction'], true)) {
                $parameter->setSchema(Schema::fromType((new StringType)->addProperties($parameter->schema->type)));
            }
        }

        $bodySchema = $operation->requestBodyObject?->content['application/json'] ?? null;
        if ($bodySchema instanceof Reference && is_a($bodySchema->fullName, FormRequest::class, true)) {
            $body = $bodySchema->resolve()->type;
            $requestClass = $bodySchema->fullName;
            foreach ((new $requestClass)->rules() as $field => $rules) {
                if (in_array('missing', $rules, true) && $body instanceof ObjectType) {
                    unset($body->properties[$field]);
                }
            }
        }

        if ($name === 'checkout') {
            $orderType = $this->typeTransformer->transform(new \Dedoc\Scramble\Support\Type\ObjectType(OrderResource::class));
            $operation->requestBodyObject = null;
            $operation->addParameters([
                Parameter::make('Idempotency-Key', 'header')
                    ->description('Optional customer-scoped replay key. If present, must be nonempty. Validation errors use idempotency_key.')
                    ->setSchema(Schema::fromType((new StringType)->setMin(1)->setMax(128)->pattern('^[A-Za-z0-9][A-Za-z0-9._:-]*$')))
                    ->example('checkout-example-001'),
            ]);
            $operation->responses = [];
            foreach ([200 => 'Existing order returned for an idempotent replay.', 201 => 'Order placed.'] as $status => $description) {
                $operation->addResponse(Response::make($status)->setDescription($description)->setContent('application/json', Schema::fromType(
                    $this->object(['data' => $orderType]),
                )));
            }
        }

        if (in_array($name, ['products.index', 'admin.products.index', 'admin.promotions.index', 'orders.index'], true)) {
            $this->documentPagination($operation);
        }

        $authenticated = in_array('auth:sanctum', $routeInfo->route->gatherMiddleware(), true);
        $errors = array_unique([...$errors, ...($authenticated ? [401] : []), ...(str_starts_with($name, 'admin.') ? [403] : []), 429, 503]);
        sort($errors);
        $operation->responses = array_values(array_filter($operation->responses ?? [], function (Response|Reference $response): bool {
            $resolved = $response instanceof Reference ? $response->resolve() : $response;

            return (int) $resolved->code < 400;
        }));
        foreach ($errors as $status) {
            $operation->addResponse($this->errorResponse($status, $name));
        }
        foreach ($operation->responses as $response) {
            if ($response instanceof Response && (int) $response->code < 400 && $response->description === '') {
                $response->setDescription((int) $response->code === 201 ? 'Created.' : 'Successful response.');
            }
        }
    }

    private function documentPagination(Operation $operation): void
    {
        $link = $this->object([
            'url' => (new StringType)->nullable(true)->format('uri'),
            'label' => new StringType,
            'page' => (new IntegerType)->nullable(true),
            'active' => new BooleanType,
        ]);
        $link->required = ['url', 'label', 'active'];
        $meta = $this->object([
            'current_page' => new IntegerType,
            'from' => (new IntegerType)->nullable(true),
            'last_page' => new IntegerType,
            'links' => (new ArrayType)->setItems($link),
            'path' => (new StringType)->format('uri'),
            'per_page' => new IntegerType,
            'to' => (new IntegerType)->nullable(true),
            'total' => new IntegerType,
        ]);
        foreach ($operation->responses ?? [] as $response) {
            if (! $response instanceof Response || (int) $response->code !== 200) {
                continue;
            }
            $schema = $response->content['application/json'] ?? null;
            if ($schema instanceof Schema && $schema->type instanceof ObjectType) {
                $schema->type->addProperty('links', $this->object([
                    'first' => (new StringType)->nullable(true)->format('uri'),
                    'last' => (new StringType)->nullable(true)->format('uri'),
                    'prev' => (new StringType)->nullable(true)->format('uri'),
                    'next' => (new StringType)->nullable(true)->format('uri'),
                ]))->addProperty('meta', $meta)->addRequired(['links', 'meta']);
            }
        }
    }

    private function errorResponse(int $status, string $name): Response
    {
        $description = match ($status) {
            400 => 'Malformed request identity (invalid client IP) rejected by the public route limiter.',
            401 => $name === 'auth.login' ? 'Incorrect credentials.' : 'Missing or invalid Sanctum bearer token.',
            403 => 'The actor lacks the required administrative permission.',
            404 => 'Resource not found or not owned/visible; an unknown promotion also returns 404.',
            409 => 'Conflict with current inventory, cart, promotion usage or order state; see the error code.',
            422 => 'Validation failed. Field errors, when present, are in error.details.fields. Promotion eligibility errors may have no field details.',
            429 => 'Redis rate limit exceeded. Retry after the Retry-After interval.',
            503 => 'Redis rate-limit admission failed; the operation was not admitted.',
        };
        $response = Response::make($status)->setDescription($description)->setContent('application/json', $this->errorSchema());
        if ($status === 429) {
            $response->addHeader('Retry-After', new Header(description: 'Seconds before retrying.', schema: Schema::fromType((new IntegerType)->setMin(1))));
        }

        return $response;
    }

    private function errorSchema(): Reference
    {
        if (! $this->openApi->components->hasSchema('ApiError')) {
            $details = $this->object([
                'fields' => (new ObjectType)->additionalProperties((new ArrayType)->setItems(new StringType)),
                'product_id' => new IntegerType,
                'available' => new IntegerType,
            ]);
            $details->required = [];
            $error = $this->object([
                'code' => (new StringType)->setDescription('Stable machine-readable code from the centralized API exception handler.'),
                'message' => new StringType,
                'request_id' => (new StringType)->format('uuid'),
                'details' => $details,
            ]);
            $error->required = ['code', 'message', 'request_id'];
            $this->openApi->components->addSchema('ApiError', Schema::fromType($this->object(['error' => $error])));
        }

        return $this->openApi->components->getSchemaReference('ApiError');
    }

    /** @param array<string, Type> $properties */
    private function object(array $properties): ObjectType
    {
        $object = new ObjectType;
        foreach ($properties as $name => $type) {
            $object->addProperty($name, $type);
        }

        return $object->setRequired(array_keys($properties));
    }
}
