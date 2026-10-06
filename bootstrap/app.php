<?php

use App\Enums\PromotionIneligibilityReason;
use App\Exceptions\Domain\AdministrationConflictException;
use App\Exceptions\Domain\CartConflictException;
use App\Exceptions\Domain\CartTotalTooLargeException;
use App\Exceptions\Domain\CheckoutConflictException;
use App\Exceptions\Domain\EmailAlreadyRegisteredException;
use App\Exceptions\Domain\EmptyCartException;
use App\Exceptions\Domain\InactiveProductException;
use App\Exceptions\Domain\InsufficientStockException;
use App\Exceptions\Domain\InvalidCredentialsException;
use App\Exceptions\Domain\InvalidOrderStatusException;
use App\Exceptions\Domain\InventoryAdjustmentConflictException;
use App\Exceptions\Domain\InventoryRestorationOverflowException;
use App\Exceptions\Domain\OrderCancellationConflictException;
use App\Exceptions\Domain\PromotionNotEligibleException;
use App\Exceptions\Domain\PromotionUsageLimitConflictException;
use App\Http\Middleware\ApiRequestContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(ApiRequestContext::class);
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api', 'api/*') ? null : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $exception): bool => $request->is('api', 'api/*') || $request->expectsJson(),
        );

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if (! $request->is('api', 'api/*')) {
                return $response;
            }

            $status = match (true) {
                $exception instanceof PromotionNotEligibleException => match ($exception->reason) {
                    PromotionIneligibilityReason::Unknown => 404,
                    PromotionIneligibilityReason::EmptyCart,
                    PromotionIneligibilityReason::InvalidCartState,
                    PromotionIneligibilityReason::GlobalLimitReached,
                    PromotionIneligibilityReason::CustomerLimitReached => 409,
                    default => 422,
                },
                $exception instanceof InvalidCredentialsException => 401,
                $exception instanceof EmailAlreadyRegisteredException => 422,
                $exception instanceof InsufficientStockException,
                $exception instanceof AdministrationConflictException,
                $exception instanceof InventoryAdjustmentConflictException,
                $exception instanceof PromotionUsageLimitConflictException,
                $exception instanceof InactiveProductException,
                $exception instanceof CartConflictException,
                $exception instanceof CheckoutConflictException,
                $exception instanceof EmptyCartException,
                $exception instanceof InvalidOrderStatusException,
                $exception instanceof InventoryRestorationOverflowException,
                $exception instanceof OrderCancellationConflictException,
                $exception instanceof CartTotalTooLargeException => 409,
                default => $response->getStatusCode(),
            };

            [$code, $message] = match ($status) {
                400 => ['MALFORMED_REQUEST', 'The request could not be understood.'],
                401 => ['UNAUTHENTICATED', $exception instanceof InvalidCredentialsException
                    ? 'The provided credentials are incorrect.' : 'Unauthenticated.'],
                403 => ['FORBIDDEN', 'You are not allowed to perform this action.'],
                404 => ['RESOURCE_NOT_FOUND', 'The requested resource was not found.'],
                405 => ['METHOD_NOT_ALLOWED', 'The request method is not allowed.'],
                409 => ['CONFLICT', 'The request conflicts with the current resource state.'],
                422 => ['VALIDATION_FAILED', 'The given data was invalid.'],
                429 => ['TOO_MANY_REQUESTS', 'Too many requests. Please try again later.'],
                503 => ['SERVICE_UNAVAILABLE', 'The service is temporarily unavailable.'],
                default => ['INTERNAL_ERROR', 'An unexpected error occurred.'],
            };

            $error = ['code' => $code, 'message' => $message];

            if ($exception instanceof AdministrationConflictException) {
                $error = ['code' => 'ADMINISTRATION_CONFLICT', 'message' => 'The resource could not be modified. Refresh it and try again.'];
            } elseif ($exception instanceof InventoryAdjustmentConflictException) {
                $error = ['code' => 'INVENTORY_ADJUSTMENT_CONFLICT', 'message' => 'The adjustment would make inventory negative or exceed the supported integer range.'];
            } elseif ($exception instanceof PromotionUsageLimitConflictException) {
                $error = ['code' => 'PROMOTION_USAGE_LIMIT_CONFLICT', 'message' => 'Usage limits cannot be reduced below already-consumed usage.'];
            }

            if ($exception instanceof PromotionNotEligibleException) {
                $error = ['code' => $exception->reason->value, 'message' => $exception->reason->message()];
            } elseif ($exception instanceof InsufficientStockException) {
                $error = [
                    'code' => 'INSUFFICIENT_STOCK',
                    'message' => 'The requested quantity is no longer available.',
                    'details' => ['product_id' => $exception->productId, 'available' => $exception->available],
                ];
            } elseif ($exception instanceof InactiveProductException) {
                $error = ['code' => 'PRODUCT_INACTIVE', 'message' => 'The product is no longer active.'];
            } elseif ($exception instanceof CartConflictException) {
                $error = ['code' => 'CART_CONFLICT', 'message' => 'The cart could not be modified. Refresh the cart and try again.'];
            } elseif ($exception instanceof CartTotalTooLargeException) {
                $error = ['code' => 'CART_TOTAL_TOO_LARGE', 'message' => 'The cart amount exceeds the supported integer range.'];
            } elseif ($exception instanceof EmptyCartException) {
                $error = ['code' => 'CART_EMPTY', 'message' => 'The cart must contain at least one item.'];
            } elseif ($exception instanceof CheckoutConflictException) {
                $error = ['code' => 'CHECKOUT_CONFLICT', 'message' => 'Checkout could not be completed. Refresh the cart and try again.'];
            }

            if ($exception instanceof InvalidOrderStatusException) {
                $error = ['code' => 'INVALID_ORDER_STATUS', 'message' => 'The order is not eligible for cancellation.'];
            } elseif ($exception instanceof InventoryRestorationOverflowException) {
                $error = ['code' => 'INVENTORY_RESTORATION_OVERFLOW', 'message' => 'Restoring inventory would exceed the supported integer range.'];
            } elseif ($exception instanceof OrderCancellationConflictException) {
                $error = ['code' => 'ORDER_CANCELLATION_CONFLICT', 'message' => 'The order could not be cancelled. Refresh the order and try again.'];
            }

            if ($exception instanceof ValidationException) {
                $error['details']['fields'] = $exception->errors();
            } elseif ($exception instanceof EmailAlreadyRegisteredException) {
                $error['details']['fields']['email'] = ['The email has already been taken.'];
            }

            $error['request_id'] = $request->attributes->get('request_id');

            $headers = $response->headers->all();
            unset($headers['content-type'], $headers['content-length']);

            return response()->json(['error' => $error], $status, $headers);
        });
    })->create();
