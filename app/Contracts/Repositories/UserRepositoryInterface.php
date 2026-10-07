<?php

namespace App\Contracts\Repositories;

use App\Enums\InternalRole;
use App\Models\User;

interface UserRepositoryInterface
{
    /** @param array{name: string, email: string, password: string} $attributes */
    public function create(array $attributes): User;

    public function findByEmail(string $email): ?User;

    public function updatePassword(User $user, string $passwordHash): void;

    public function findByEmailForUpdate(string $email): ?User;

    public function assignRole(User $user, InternalRole $role): void;

    public function removeRole(User $user, InternalRole $role): void;
}
