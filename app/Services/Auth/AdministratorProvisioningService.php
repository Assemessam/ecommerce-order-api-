<?php

namespace App\Services\Auth;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use LogicException;

class AdministratorProvisioningService
{
    public function __construct(private UserRepositoryInterface $users) {}

    public function grantAdministrator(string $email): User
    {
        if (! App::environment('local')) {
            throw new LogicException('Administrator provisioning is only available in the local environment.');
        }

        $user = $this->users->findByEmail(Str::lower(trim($email)))
            ?? throw (new ModelNotFoundException)->setModel(User::class);
        $this->users->grantAdministrator($user);

        return $user;
    }
}
