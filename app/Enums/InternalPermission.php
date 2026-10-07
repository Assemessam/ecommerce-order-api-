<?php

namespace App\Enums;

enum InternalPermission: string
{
    case ProductsViewAdmin = 'products.view-admin';
    case ProductsCreate = 'products.create';
    case ProductsUpdate = 'products.update';
    case InventoryAdjust = 'inventory.adjust';
    case PromotionsViewAdmin = 'promotions.view-admin';
    case PromotionsCreate = 'promotions.create';
    case PromotionsUpdate = 'promotions.update';
}
