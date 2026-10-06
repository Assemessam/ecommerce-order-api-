<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Placed = 'placed';
    case Cancelled = 'cancelled';
}
