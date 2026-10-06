<?php

namespace App\Policies;

use App\Models\Cart;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CartPolicy
{
    public function view(User $user, Cart $cart): Response
    {
        return $user->id === $cart->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Cart $cart): Response
    {
        return $this->view($user, $cart);
    }
}
