<?php

use App\Exceptions\Domain\EmailAlreadyRegisteredException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

it('returns the documented JSON envelope for unknown API routes without an Accept header', function () {
    $response = $this->get('/api/does-not-exist')->assertNotFound();
    $requestId = $response->headers->get('X-Request-ID');
    expect(Str::isUuid($requestId))->toBeTrue();
    $response->assertExactJson([
        'error' => [
            'code' => 'RESOURCE_NOT_FOUND',
            'message' => 'The requested resource was not found.',
            'request_id' => $requestId,
        ],
    ]);
});

it('returns JSON authentication and validation errors without an Accept header', function () {
    $this->get('/api/auth/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    $this->post('/api/auth/login', [])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('maps HTTP failures to safe stable error codes and preserves status codes', function (int $status, string $code, string $message) {
    Route::middleware('api')->get('/api/test-error', fn () => abort($status, 'Internal implementation detail'));

    $this->getJson('/api/test-error')->assertStatus($status)->assertJsonPath('error.code', $code)
        ->assertJsonPath('error.message', $message)->assertJsonMissingPath('trace');
})->with([
    [403, 'FORBIDDEN', 'You are not allowed to perform this action.'],
    [409, 'CONFLICT', 'The request conflicts with the current resource state.'],
    [500, 'INTERNAL_ERROR', 'An unexpected error occurred.'],
]);

it('maps an email persistence conflict to the same validation envelope', function () {
    Route::middleware('api')->post('/api/test-email-conflict', function (): void {
        throw new EmailAlreadyRegisteredException;
    });

    $this->postJson('/api/test-email-conflict')->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonPath('error.details.fields.email', ['The email has already been taken.']);
});

it('returns a safe 500 and reports the exception with request context', function (bool $debug) {
    config(['app.debug' => $debug]);
    Exceptions::fake();
    Route::middleware('api')->get('/api/test-unexpected-error', function (): void {
        throw new RuntimeException('Sensitive internal database information');
    });

    $response = $this->getJson('/api/test-unexpected-error')->assertInternalServerError();
    $requestId = $response->json('error.request_id');
    expect(Str::isUuid($requestId))->toBeTrue();
    $response->assertExactJson(['error' => [
        'code' => 'INTERNAL_ERROR',
        'message' => 'An unexpected error occurred.',
        'request_id' => $requestId,
    ]])->assertHeader('X-Request-ID', $requestId);
    expect(Context::get('request_id'))->toBe($requestId);
    Exceptions::assertReported(RuntimeException::class);
})->with(['production responses' => false, 'debug responses' => true]);

it('prevents caching token responses and adds a generated request ID', function () {
    $response = $this->postJson('/api/auth/login', [])->assertUnprocessable();

    expect(Str::isUuid($response->headers->get('X-Request-ID')))->toBeTrue();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});
