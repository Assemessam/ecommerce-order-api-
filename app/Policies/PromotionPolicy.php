<?php

namespace App\Policies;

use App\Models\User;

class PromotionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin === true;
    }

    public function view(User $user): bool
    {
        return $user->is_admin === true;
    }

    public function create(User $user): bool
    {
        return $user->is_admin === true;
    }

    public function update(User $user): bool
    {
        return $user->is_admin === true;
    }
}
