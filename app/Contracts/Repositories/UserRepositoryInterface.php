<?php

namespace App\Contracts\Repositories;

use App\Models\User;

interface UserRepositoryInterface
{
    /** @param array{name: string, email: string, password: string} $attributes */
    public function create(array $attributes): User;

    public function findByEmail(string $email): ?User;

    public function updatePassword(User $user, string $passwordHash): void;

    public function grantAdministrator(User $user): void;
}
