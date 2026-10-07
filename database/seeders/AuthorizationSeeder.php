<?php

namespace Database\Seeders;

use App\Enums\InternalPermission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AuthorizationSeeder extends Seeder
{
    public const string GUARD = 'web';

    public const array PERMISSIONS = [
        InternalPermission::ProductsViewAdmin->value,
        InternalPermission::ProductsCreate->value,
        InternalPermission::ProductsUpdate->value,
        InternalPermission::InventoryAdjust->value,
        InternalPermission::PromotionsViewAdmin->value,
        InternalPermission::PromotionsCreate->value,
        InternalPermission::PromotionsUpdate->value,
    ];

    /** @var array<string, list<string>> */
    public const array ROLE_PERMISSIONS = [
        'product_manager' => [
            InternalPermission::ProductsViewAdmin->value,
            InternalPermission::ProductsCreate->value,
            InternalPermission::ProductsUpdate->value,
            InternalPermission::InventoryAdjust->value,
        ],
        'promotion_manager' => [
            InternalPermission::PromotionsViewAdmin->value,
            InternalPermission::PromotionsCreate->value,
            InternalPermission::PromotionsUpdate->value,
        ],
        'administrator' => self::PERMISSIONS,
    ];

    /**
     * Run the database seeds.
     */
    public function run(PermissionRegistrar $permissions): void
    {
        $permissions->forgetCachedPermissions();

        try {
            DB::transaction(function (): void {
                foreach (self::PERMISSIONS as $permission) {
                    Permission::findOrCreate($permission, self::GUARD);
                }

                foreach (self::ROLE_PERMISSIONS as $name => $rolePermissions) {
                    Role::findOrCreate($name, self::GUARD)->syncPermissions($rolePermissions);
                }
            });
        } finally {
            $permissions->forgetCachedPermissions();
        }
    }
}
