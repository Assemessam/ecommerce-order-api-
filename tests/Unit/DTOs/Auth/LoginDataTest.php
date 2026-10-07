<?php

use App\DTOs\Auth\LoginData;

it('maps exact login credentials and the client name without retaining unrelated input', function () {
    $data = LoginData::fromArray([
        'email' => 'CUSTOMER@example.com', 'password' => ' ExactPassword123! ', 'device_name' => 'Postman',
        'roles' => ['administrator'], 'name' => 'ignored', 'is_admin' => true,
    ]);

    expect(get_object_vars($data))->toBe([
        'email' => 'CUSTOMER@example.com', 'password' => ' ExactPassword123! ', 'deviceName' => 'Postman',
    ]);
});

it('represents missing and explicit null client names identically', function (array $optional) {
    $data = LoginData::fromArray(['email' => 'customer@example.com', 'password' => 'Password123!'] + $optional);

    expect($data->deviceName)->toBeNull();
})->with(['missing' => [[]], 'null' => [['device_name' => null]]]);

it('rejects missing required fields rather than manufacturing credentials', function (string $field) {
    $input = ['email' => 'customer@example.com', 'password' => 'Password123!'];
    unset($input[$field]);

    expect(fn () => LoginData::fromArray($input))->toThrow(InvalidArgumentException::class);
})->with(['email', 'password']);

it('rejects malformed field types without scalar coercion', function (string $field, mixed $value) {
    $input = ['email' => 'customer@example.com', 'password' => 'Password123!'];
    $input[$field] = $value;

    expect(fn () => LoginData::fromArray($input))->toThrow(InvalidArgumentException::class);
})->with([
    'numeric email' => ['email', 123], 'null email' => ['email', null],
    'numeric password' => ['password', 123], 'null password' => ['password', null],
    'boolean password' => ['password', true], 'array password' => ['password', ['secret']],
    'numeric client' => ['device_name', 123],
]);
