<?php

namespace App\Enums;

enum InternalRole: string
{
    case ProductManager = 'product_manager';
    case PromotionManager = 'promotion_manager';
    case Administrator = 'administrator';
}
