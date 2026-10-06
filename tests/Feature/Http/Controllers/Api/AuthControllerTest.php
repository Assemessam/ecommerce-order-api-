<?php

use App\Exceptions\Domain\EmailAlreadyRegisteredException;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

uses(LazilyRefreshDatabase::class);

/** @return array{name: string, email: string, password: string, password_confirmation: string} */
function registrationPayload(): array
{
    $customer = User::factory()->make();

    return [
        'name' => $customer->name,
        'email' => $customer->email,
        'password' => 'StrongPassword123!',
        'password_confirmation' => 'StrongPassword123!',
    ];
}

it('runs database tests on the dedicated PostgreSQL database', function () {
    expect(DB::selectOne('SELECT current_database() AS name')->name)->toBe('ecommerce_order_api_test');
});

describe('registration', function () {
    it('creates a customer with a hashed password and a usable hashed token', function () {
        $payload = registrationPayload();
        $payload['email'] = '  CUSTOMER@EXAMPLE.COM  ';
        $payload['device_name'] = 'Postman';
        $payload['email_verified_at'] = '2026-01-01';
        $payload['remember_token'] = 'attacker-controlled';
        $payload['id'] = 999;

        $response = $this->postJson('/api/auth/register', $payload)
            ->assertCreated()
            ->assertJsonPath('data.user.name', $payload['name'])
            ->assertJsonPath('data.user.email', 'customer@example.com')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email', 'created_at', 'updated_at'], 'token', 'token_type']]);

        $user = User::query()->sole();
        expect(Hash::check($payload['password'], $user->password))->toBeTrue();
        expect($user->password)->not->toBe($payload['password']);
        expect($user->email_verified_at)->toBeNull();
        expect($user->remember_token)->toBeNull();
        expect($user->id)->not->toBe(999);
        expect(array_keys($response->json('data.user')))->toBe(['id', 'name', 'email', 'created_at', 'updated_at']);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $storedToken = PersonalAccessToken::findToken($response->json('data.token'));
        expect($storedToken->name)->toBe('Postman');
        expect($storedToken->token)->not->toBe($response->json('data.token'));
        expect($storedToken->tokenable_id)->toBe($user->id);

        $this->withToken($response->json('data.token'))->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('data.id', $user->id);
    });

    it('returns 422 for an existing email regardless of case or surrounding spaces', function () {
        $user = User::factory()->create(['email' => 'customer@example.com']);
        $payload = registrationPayload();
        $payload['email'] = ' CUSTOMER@EXAMPLE.COM ';

        $this->postJson('/api/auth/register', $payload)->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.fields.email', ['The email has already been taken.']);

        $this->assertModelExists($user);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    });

    it('returns 422 with field errors for missing registration input', function () {
        $response = $this->postJson('/api/auth/register', [])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.message', 'The given data was invalid.')
            ->assertJsonPath('error.details.fields.name', ['The name field is required.'])
            ->assertJsonPath('error.details.fields.email', ['The email field is required.'])
            ->assertJsonPath('error.details.fields.password', ['The password field is required.']);

        expect($response->json('error.request_id'))->toBeString();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    });

    it('returns 422 for invalid registration fields', function (string $field, mixed $value, string $message) {
        $payload = registrationPayload();
        $payload[$field] = $value;

        $this->postJson('/api/auth/register', $payload)->assertUnprocessable()
            ->assertJsonPath('error.details.fields.'.$field.'.0', $message);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    })->with([
        'blank name' => ['name', '  ', 'The name field is required.'],
        'non-string name' => ['name', ['bad'], 'The name field must be a string.'],
        'long name' => ['name', str_repeat('n', 256), 'The name field must not be greater than 255 characters.'],
        'invalid email' => ['email', 'invalid', 'The email field must be a valid email address.'],
        'non-string email' => ['email', ['bad'], 'The email field must be a string.'],
        'long email' => ['email', str_repeat('a', 250).'@example.com', 'The email field must not be greater than 255 characters.'],
        'non-string password' => ['password', ['bad'], 'The password field must be a string.'],
        'non-string device name' => ['device_name', ['bad'], 'The device name field must be a string.'],
        'long device name' => ['device_name', str_repeat('d', 101), 'The device name field must not be greater than 100 characters.'],
    ]);

    it('returns 422 when password confirmation is missing or different', function (?string $confirmation) {
        $payload = registrationPayload();
        $payload['password_confirmation'] = $confirmation;

        $this->postJson('/api/auth/register', $payload)->assertUnprocessable()
            ->assertJsonPath('error.details.fields.password', ['The password field confirmation does not match.']);

        $this->assertDatabaseCount('users', 0);
    })->with(['missing' => null, 'different' => 'DifferentPassword123!']);

    it('returns 422 for passwords that fail the configured policy', function (string $password, string $message) {
        $payload = registrationPayload();
        $payload['password'] = $payload['password_confirmation'] = $password;

        $this->postJson('/api/auth/register', $payload)->assertUnprocessable()
            ->assertJsonPath('error.details.fields.password.0', $message);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    })->with([
        'too short' => ['Short123!', 'The password field must be at least 12 characters.'],
        'no uppercase' => ['lowercase123!', 'The password field must contain at least one uppercase and one lowercase letter.'],
        'no lowercase' => ['UPPERCASE123!', 'The password field must contain at least one uppercase and one lowercase letter.'],
        'no number' => ['StrongPassword!!', 'The password field must contain at least one number.'],
        'no symbol' => ['StrongPassword123', 'The password field must contain at least one symbol.'],
        'too many bytes' => ['Aa1!'.str_repeat('x', 69), 'The password must not exceed 72 bytes.'],
        'multibyte overflow' => ['Aa1!'.str_repeat('é', 35), 'The password must not exceed 72 bytes.'],
        'null byte' => ["StrongPassword123!\0", 'The password must not contain null bytes.'],
    ]);

    it('accepts the minimum password length and uses a default client name', function () {
        $payload = registrationPayload();
        $payload['password'] = $payload['password_confirmation'] = 'ValidPass12!';

        $this->postJson('/api/auth/register', $payload)->assertCreated();

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'api-client']);
    });

    it('handles a persistence uniqueness conflict without creating another token', function () {
        $user = User::factory()->create();
        $payload = registrationPayload();
        $payload['email'] = $user->email;

        expect(fn () => app(AuthService::class)->register($payload))
            ->toThrow(EmailAlreadyRegisteredException::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    });

    it('hashes a supplied password even when it looks like an existing password hash', function () {
        $payload = registrationPayload();
        $payload['password'] = $payload['password_confirmation'] = Hash::make('OriginalPassword123!');

        $this->postJson('/api/auth/register', $payload)->assertCreated();

        $user = User::query()->sole();
        expect($user->password)->not->toBe($payload['password']);
        expect(Hash::check($payload['password'], $user->password))->toBeTrue();
    });

    it('accepts a password of exactly 72 bytes', function () {
        $payload = registrationPayload();
        $payload['password'] = $payload['password_confirmation'] = 'Aa1!'.str_repeat('x', 68);

        $this->postJson('/api/auth/register', $payload)->assertCreated();

        expect(Hash::check($payload['password'], User::query()->sole()->password))->toBeTrue();
    });

    it('rolls back the customer when token persistence fails', function () {
        $payload = registrationPayload();
        Exceptions::fake();
        $event = 'eloquent.creating: '.PersonalAccessToken::class;
        Event::listen($event, function (): void {
            throw new RuntimeException('Simulated token persistence failure');
        });

        try {
            $this->postJson('/api/auth/register', $payload)->assertInternalServerError()
                ->assertJsonPath('error.code', 'INTERNAL_ERROR');
        } finally {
            Event::forget($event);
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Exceptions::assertReported(RuntimeException::class);
    });
});

describe('login', function () {
    it('issues a usable token for valid credentials and normalizes the email', function () {
        $user = User::factory()->unverified()->create();

        $response = $this->postJson('/api/auth/login', [
            'email' => ' '.strtoupper($user->email).' ',
            'password' => 'password',
            'device_name' => 'Postman',
        ])->assertOk()->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.token_type', 'Bearer');

        expect(array_keys($response->json('data.user')))->toBe(['id', 'name', 'email', 'created_at', 'updated_at']);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->withToken($response->json('data.token'))->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('data.id', $user->id);
    });

    it('returns the same 401 for incorrect passwords and unknown email addresses', function () {
        $user = User::factory()->create();

        $incorrect = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('error.message', 'The provided credentials are incorrect.');
        $unknown = $this->postJson('/api/auth/login', ['email' => 'unknown@example.com', 'password' => 'wrong-password'])
            ->assertUnauthorized();

        expect($unknown->json('error.code'))->toBe($incorrect->json('error.code'));
        expect($unknown->json('error.message'))->toBe($incorrect->json('error.message'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    });

    it('returns 422 for missing login credentials', function () {
        $this->postJson('/api/auth/login', [])->assertUnprocessable()
            ->assertJsonPath('error.details.fields.email', ['The email field is required.'])
            ->assertJsonPath('error.details.fields.password', ['The password field is required.']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    });

    it('returns 422 for invalid login fields', function (string $field, mixed $value, string $message) {
        $payload = ['email' => 'customer@example.com', 'password' => 'StrongPassword123!'];
        $payload[$field] = $value;

        $this->postJson('/api/auth/login', $payload)->assertUnprocessable()
            ->assertJsonPath('error.details.fields.'.$field.'.0', $message);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    })->with([
        'invalid email' => ['email', 'invalid', 'The email field must be a valid email address.'],
        'non-string email' => ['email', ['bad'], 'The email field must be a string.'],
        'non-string password' => ['password', ['bad'], 'The password field must be a string.'],
        'password overflow' => ['password', 'Aa1!'.str_repeat('é', 35), 'The password must not exceed 72 bytes.'],
        'null byte' => ['password', "StrongPassword123!\0", 'The password must not contain null bytes.'],
        'invalid device name' => ['device_name', ['bad'], 'The device name field must be a string.'],
    ]);

    it('upgrades an outdated password hash on successful login', function () {
        $oldHash = Hash::make('StrongPassword123!');
        $user = User::factory()->create(['password' => $oldHash]);
        Hash::driver()->setRounds(5);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'StrongPassword123!'])->assertOk();

        expect($user->fresh()->password)->not->toBe($oldHash);
        expect(Hash::needsRehash($user->fresh()->password))->toBeFalse();
        expect(Hash::check('StrongPassword123!', $user->fresh()->password))->toBeTrue();
    });
});

describe('protected endpoints', function () {
    it('returns 401 without a valid bearer token', function (string $method, string $path, ?string $token) {
        $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];

        $this->json($method, $path, [], $headers)->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('error.message', 'Unauthenticated.');
    })->with([
        'me without token' => ['GET', '/api/auth/me', null],
        'me with invalid token' => ['GET', '/api/auth/me', 'invalid-token'],
        'logout without token' => ['POST', '/api/auth/logout', null],
        'logout with invalid token' => ['POST', '/api/auth/logout', 'invalid-token'],
    ]);

    it('returns only the authenticated customer public profile', function () {
        $user = User::factory()->create();
        User::factory()->create();
        $token = $user->createToken('Postman')->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertExactJson([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at->toISOString(),
                'updated_at' => $user->updated_at->toISOString(),
            ],
        ]);
    });

    it('returns 401 for a web session without a bearer token', function () {
        $this->actingAs(User::factory()->create(), 'web');

        $this->getJson('/api/auth/me')->assertUnauthorized();
    });

    it('revokes only the current token and rejects it on subsequent requests', function () {
        $user = User::factory()->create();
        $first = $user->createToken('First device');
        $second = $user->createToken('Second device');
        $otherCustomerToken = User::factory()->create()->createToken('Other customer');

        $this->withToken($first->plainTextToken)->postJson('/api/auth/logout')->assertNoContent();

        $this->assertModelMissing($first->accessToken);
        $this->assertModelExists($second->accessToken);
        $this->assertModelExists($otherCustomerToken->accessToken);
        $this->app['auth']->forgetGuards();
        $this->withToken($first->plainTextToken)->getJson('/api/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($first->plainTextToken)->postJson('/api/auth/logout')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($second->plainTextToken)->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('data.id', $user->id);
    });

    it('allows separate logins without revoking earlier tokens', function () {
        $user = User::factory()->create();
        $credentials = ['email' => $user->email, 'password' => 'password'];

        $first = $this->postJson('/api/auth/login', $credentials)->assertOk()->json('data.token');
        $second = $this->postJson('/api/auth/login', $credentials)->assertOk()->json('data.token');

        expect($second)->not->toBe($first);
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->withToken($first)->getJson('/api/auth/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($second)->getJson('/api/auth/me')->assertOk();
    });
});

describe('throttling', function () {
    it('returns 429 after five registration attempts and resets after a minute', function () {
        $this->freezeTime();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/register', [])->assertUnprocessable();
        }

        $this->postJson('/api/auth/register', [])->assertTooManyRequests()
            ->assertHeader('Retry-After', '60')->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/register', [])->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    });

    it('returns 429 after five login attempts using the normalized email and IP', function () {
        $this->freezeTime();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/login', ['email' => 'customer@example.com', 'password' => 'wrong'])
                ->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', ['email' => ' CUSTOMER@EXAMPLE.COM ', 'password' => 'wrong'])
            ->assertTooManyRequests()->assertHeader('Retry-After', '60')
            ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
        $this->postJson('/api/auth/login', ['email' => 'other@example.com', 'password' => 'wrong'])
            ->assertUnauthorized();
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/login', ['email' => 'customer@example.com', 'password' => 'wrong'])
            ->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    });

    it('returns 429 when email rotation exceeds the per-IP login limit', function () {
        $this->freezeTime();
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson('/api/auth/login', ['email' => 'customer'.$attempt.'@example.com', 'password' => 'wrong'])
                ->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', ['email' => 'fresh@example.com', 'password' => 'wrong'])
            ->assertTooManyRequests()->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    });

    it('keeps registration and login rate limits separate', function () {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/register', [])->assertUnprocessable();
        }

        $this->postJson('/api/auth/register', [])->assertTooManyRequests();
        $this->postJson('/api/auth/login', ['email' => 'customer@example.com', 'password' => 'wrong'])
            ->assertUnauthorized();
    });
});
