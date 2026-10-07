<?php

use App\DTOs\Auth\RegisterUserData;

it('maps registration fields without changing credentials or retaining authority fields', function () {
    $data = RegisterUserData::fromArray([
        'name' => 'Customer', 'email' => 'CUSTOMER@example.com', 'password' => ' ExactPassword123! ',
        'device_name' => 'Postman', 'password_confirmation' => 'ignored',
        'roles' => ['administrator'], 'permissions' => ['products.create'], 'is_admin' => true,
    ]);

    expect(get_object_vars($data))->toBe([
        'name' => 'Customer', 'email' => 'CUSTOMER@example.com', 'password' => ' ExactPassword123! ', 'deviceName' => 'Postman',
    ]);
});

it('represents missing and explicit null client names identically', function (array $optional) {
    $data = RegisterUserData::fromArray(['name' => 'Customer', 'email' => 'customer@example.com', 'password' => 'Password123!'] + $optional);

    expect($data->deviceName)->toBeNull();
})->with(['missing' => [[]], 'null' => [['device_name' => null]]]);

it('rejects missing required fields rather than manufacturing credentials', function (string $field) {
    $input = ['name' => 'Customer', 'email' => 'customer@example.com', 'password' => 'Password123!'];
    unset($input[$field]);

    expect(fn () => RegisterUserData::fromArray($input))->toThrow(InvalidArgumentException::class);
})->with(['name', 'email', 'password']);

it('rejects malformed field types without scalar coercion', function (string $field, mixed $value) {
    $input = ['name' => 'Customer', 'email' => 'customer@example.com', 'password' => 'Password123!'];
    $input[$field] = $value;

    expect(fn () => RegisterUserData::fromArray($input))->toThrow(InvalidArgumentException::class);
})->with([
    'numeric name' => ['name', 123], 'null name' => ['name', null],
    'numeric email' => ['email', 123], 'null email' => ['email', null],
    'numeric password' => ['password', 123], 'null password' => ['password', null],
    'boolean password' => ['password', true], 'array password' => ['password', ['secret']],
    'numeric client' => ['device_name', 123],
]);
