<?php

namespace App\Policies;

use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('products.view-admin');
    }

    public function view(User $user): bool
    {
        return $user->can('products.view-admin');
    }

    public function create(User $user): bool
    {
        return $user->can('products.create');
    }

    public function update(User $user): bool
    {
        return $user->can('products.update');
    }

    public function adjustInventory(User $user): bool
    {
        return $user->can('inventory.adjust');
    }
}
