<?php

namespace App\Services\Auth;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\DTOs\Auth\AuthenticationResult;
use App\Exceptions\Domain\EmailAlreadyRegisteredException;
use App\Exceptions\Domain\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function __construct(private UserRepositoryInterface $users) {}

    /** @param array{name: string, email: string, password: string, device_name?: ?string} $data */
    public function register(#[\SensitiveParameter] array $data): AuthenticationResult
    {
        return DB::transaction(function () use ($data): AuthenticationResult {
            try {
                $user = $this->users->create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => Hash::make($data['password']),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new EmailAlreadyRegisteredException;
            }

            return new AuthenticationResult($user, $user->createToken($data['device_name'] ?? 'api-client'));
        });
    }

    /** @param array{email: string, password: string, device_name?: ?string} $data */
    public function login(#[\SensitiveParameter] array $data): AuthenticationResult
    {
        $user = $this->users->findByEmail($data['email']);

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw new InvalidCredentialsException;
        }

        return DB::transaction(function () use ($user, $data): AuthenticationResult {
            if (Hash::needsRehash($user->password)) {
                $this->users->updatePassword($user, Hash::make($data['password']));
            }

            return new AuthenticationResult($user, $user->createToken($data['device_name'] ?? 'api-client'));
        });
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }
}
