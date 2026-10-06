<?php

namespace App\DTOs\Auth;

use App\Models\User;
use Laravel\Sanctum\NewAccessToken;

final readonly class AuthenticationResult
{
    public function __construct(public User $user, public NewAccessToken $token) {}
}
