<?php

use App\Exceptions\Domain\EmailAlreadyRegisteredException;
use App\Exceptions\Domain\InvalidCredentialsException;
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
                $exception instanceof InvalidCredentialsException => 401,
                $exception instanceof EmailAlreadyRegisteredException => 422,
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
