<?php

namespace App\Enums;

enum OrderEventType: string
{
    case OrderPlaced = 'order.placed';
    case OrderCancelled = 'order.cancelled';
}
