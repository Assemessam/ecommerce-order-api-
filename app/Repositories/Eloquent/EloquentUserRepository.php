<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\User;

class EloquentUserRepository implements UserRepositoryInterface
{
    public function create(#[\SensitiveParameter] array $attributes): User
    {
        return User::query()->create($attributes);
    }

    public function findByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }

    public function updatePassword(User $user, string $passwordHash): void
    {
        $user->update(['password' => $passwordHash]);
    }

    public function grantAdministrator(User $user): void
    {
        $user->is_admin = true;
        $user->save();
    }
}
