<?php

namespace App\Services\Auth;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\InternalRole;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

class RoleProvisioningService
{
    public function __construct(private UserRepositoryInterface $users) {}

    public function grantRole(string $email, string $role): User
    {
        return $this->changeRole($email, $role, true);
    }

    public function revokeRole(string $email, string $role): User
    {
        return $this->changeRole($email, $role, false);
    }

    private function changeRole(string $email, string $role, bool $grant): User
    {
        if (! App::environment('local')) {
            throw new LogicException('Role provisioning is only available in the local environment.');
        }

        $internalRole = InternalRole::tryFrom($role)
            ?? throw new InvalidArgumentException('Use a canonical role: product_manager, promotion_manager, or administrator.');

        return DB::transaction(function () use ($email, $internalRole, $grant): User {
            $user = $this->users->findByEmailForUpdate(Str::lower(trim($email)))
                ?? throw (new ModelNotFoundException)->setModel(User::class);

            if ($grant) {
                $this->users->assignRole($user, $internalRole);
            } else {
                $this->users->removeRole($user, $internalRole);
            }

            return $user;
        }, attempts: 3);
    }
}
