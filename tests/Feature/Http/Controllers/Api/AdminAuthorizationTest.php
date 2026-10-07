<?php

use App\DTOs\Product\CreateProductData;
use App\DTOs\Promotion\CreatePromotionData;
use App\Models\User;
use App\Services\Product\ProductService;
use App\Services\Promotion\PromotionAdministrationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

dataset('admin endpoints', [
    'product list' => ['GET', '/api/admin/products'],
    'product detail' => ['GET', '/api/admin/products/1'],
    'product create' => ['POST', '/api/admin/products'],
    'product update' => ['PATCH', '/api/admin/products/1'],
    'promotion list' => ['GET', '/api/admin/promotions'],
    'promotion detail' => ['GET', '/api/admin/promotions/1'],
    'promotion create' => ['POST', '/api/admin/promotions'],
    'promotion update' => ['PATCH', '/api/admin/promotions/1'],
]);

it('returns 401 for absent invalid or revoked tokens on admin endpoints', function (string $method, string $path, string $authentication) {
    $administrator = User::factory()->administrator()->create();
    $token = $administrator->createToken('revoked');
    $token->accessToken->delete();

    if ($authentication !== 'missing') {
        $this->withToken($authentication === 'revoked' ? $token->plainTextToken : 'invalid');
    }

    $this->json($method, $path, ['is_admin' => true, 'role' => 'admin'])
        ->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('promotions', 0);
})->with('admin endpoints')->with(['missing', 'invalid', 'revoked']);

it('returns 403 before validating or looking up resources for customers', function (string $method, string $path) {
    $customer = User::factory()->create();

    $this->withToken($customer->createToken('customer', ['*'])->plainTextToken)
        ->json($method, $path, ['is_admin' => true, 'role' => 'admin'])
        ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('promotions', 0);
})->with('admin endpoints');

it('keeps public registration unprivileged despite forged role and administrator fields', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Customer', 'email' => 'customer@example.test',
        'password' => 'ValidPassword123!', 'password_confirmation' => 'ValidPassword123!',
        'is_admin' => true, 'role' => 'administrator', 'roles' => ['administrator'],
        'role_id' => 1, 'role_ids' => [1], 'permission' => 'products.create', 'permissions' => ['*'],
    ])->assertCreated()->assertJsonMissingPath('data.user.is_admin')
        ->assertJsonMissingPath('data.user.roles')->assertJsonMissingPath('data.user.permissions');

    $customer = User::query()->sole();
    expect($customer->is_admin)->toBeFalse();
    expect($customer->roles()->count())->toBe(0);
    expect($customer->permissions()->count())->toBe(0);
    $this->app['auth']->forgetGuards();
    $this->withToken($response->json('data.token'))->getJson('/api/admin/products')->assertForbidden();
});

it('ignores administrator roles and permissions during customer mass assignment', function () {
    $customer = User::factory()->create();

    $customer->fill(['name' => 'Updated Customer', 'is_admin' => true, 'role' => 'administrator',
        'roles' => ['administrator'], 'role_id' => 1, 'role_ids' => [1],
        'permission' => 'products.create', 'permissions' => ['products.create']])->save();

    expect($customer->fresh()->is_admin)->toBeFalse();
    expect($customer->fresh()->name)->toBe('Updated Customer');
    expect($customer->roles()->count())->toBe(0);
    expect($customer->permissions()->count())->toBe(0);
    expect($customer->toArray())->not->toHaveKey('is_admin');
});

it('uses current permissions even when a revoked administrator retains the legacy flag and wildcard token', function () {
    $administrator = User::factory()->administrator()->create(['is_admin' => true]);
    $token = $administrator->createToken('admin')->plainTextToken;
    $this->withToken($token)->getJson('/api/admin/products')->assertOk();
    $administrator->removeRole('administrator');
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/admin/products')->assertForbidden();
    expect($administrator->fresh()->is_admin)->toBeTrue();
});

it('returns 401 when an administrator has only a web session', function () {
    $this->actingAs(User::factory()->administrator()->create(), 'web')
        ->getJson('/api/admin/products')->assertUnauthorized();
});

it('authorizes service mutations even when called without HTTP middleware', function (string $service, string $method, array $data) {
    $customer = User::factory()->create();
    $command = $service === ProductService::class ? CreateProductData::fromArray($data) : CreatePromotionData::fromArray($data);

    expect(fn () => app($service)->$method($customer, $command))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('promotions', 0);
})->with([
    [ProductService::class, 'createProduct', ['name' => 'P', 'sku' => 'P', 'price_minor' => 0]],
    [PromotionAdministrationService::class, 'createPromotion', ['code' => 'P', 'type' => 'fixed', 'value' => 1]],
]);
