<?php

namespace App\Policies;

use App\Models\User;

class PromotionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('promotions.view-admin');
    }

    public function view(User $user): bool
    {
        return $user->can('promotions.view-admin');
    }

    public function create(User $user): bool
    {
        return $user->can('promotions.create');
    }

    public function update(User $user): bool
    {
        return $user->can('promotions.update');
    }
}
