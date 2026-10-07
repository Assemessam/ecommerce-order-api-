<?php

namespace App\DTOs\Auth;

use InvalidArgumentException;

final readonly class LoginData
{
    public function __construct(
        public string $email,
        #[\SensitiveParameter] public string $password,
        public ?string $deviceName = null,
    ) {}

    /** @param array{email: string, password: string, device_name?: ?string} $data */
    public static function fromArray(#[\SensitiveParameter] array $data): self
    {
        foreach (['email', 'password'] as $field) {
            if (! array_key_exists($field, $data) || ! is_string($data[$field])) {
                throw new InvalidArgumentException("The {$field} field must be a string.");
            }
        }

        if (array_key_exists('device_name', $data) && $data['device_name'] !== null && ! is_string($data['device_name'])) {
            throw new InvalidArgumentException('The device_name field must be a string or null.');
        }

        return new self($data['email'], $data['password'], $data['device_name'] ?? null);
    }
}
