<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

return new class extends Migration
{
    /** Frozen initial authorization definitions; later changes belong in new migrations. */
    private const array ROLE_PERMISSIONS = [
        'product_manager' => ['products.view-admin', 'products.create', 'products.update', 'inventory.adjust'],
        'promotion_manager' => ['promotions.view-admin', 'promotions.create', 'promotions.update'],
        'administrator' => [
            'products.view-admin', 'products.create', 'products.update', 'inventory.adjust',
            'promotions.view-admin', 'promotions.create', 'promotions.update',
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $permissions = app(PermissionRegistrar::class);
        $permissions->forgetCachedPermissions();

        try {
            DB::transaction(function (): void {
                foreach (self::ROLE_PERMISSIONS['administrator'] as $permission) {
                    Permission::findOrCreate($permission, 'web');
                }

                foreach (self::ROLE_PERMISSIONS as $name => $rolePermissions) {
                    Role::findOrCreate($name, 'web')->syncPermissions($rolePermissions);
                }

                $administrator = Role::findByName('administrator', 'web');

                /** The migration uses a users-table snapshot rather than evolving User scopes/events. */
                $legacyUsers = new class extends Model
                {
                    use HasRoles;

                    protected $table = 'users';

                    protected string $guard_name = 'web';

                    public function getMorphClass(): string
                    {
                        return 'App\\Models\\User';
                    }

                    protected function getDefaultGuardName(): string
                    {
                        return $this->guard_name;
                    }
                };

                $legacyUsers->newQuery()->where('is_admin', true)->eachById(function (Model $user) use ($administrator): void {
                    $user->assignRole($administrator);
                });
            });
        } finally {
            $permissions->forgetCachedPermissions();
        }
    }

    /**
     * Retain assignments on a data-only rollback. A package-schema rollback drops its tables.
     */
    public function down(): void {}
};
