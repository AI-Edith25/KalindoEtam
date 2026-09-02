<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * New pages: Maintenance > Price Zones and Maintenance > Item Prices. Every role that already
 * holds the matching master.items.{action} is trusted with the same action here (both are
 * item-master-adjacent), and Admin always gets the full set regardless — same idempotent
 * withTrashed()->firstOrNew()+restore() shape as every other permission-adding migration here.
 */
return new class extends Migration
{
    protected array $newPermissions = [
        'master.price_zones' => ['view', 'create', 'update', 'delete'],
        'master.item_prices' => ['view', 'create', 'update', 'delete', 'import'],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            $permissionNames = [];

            foreach ($this->newPermissions as $page => $actions) {
                foreach ($actions as $action) {
                    $permissionNames[] = "{$page}.{$action}";
                }
            }

            foreach ($permissionNames as $name) {
                $permission = Permission::withTrashed()->firstOrNew(['name' => $name, 'guard_name' => 'web']);

                if ($permission->trashed()) {
                    $permission->restore();
                } elseif (! $permission->exists) {
                    $permission->save();
                }
            }

            Role::query()->with('permissions')->each(function (Role $role) use ($permissionNames) {
                if ($role->name === 'Admin') {
                    $role->givePermissionTo($permissionNames);

                    return;
                }

                foreach ($this->newPermissions as $page => $actions) {
                    foreach ($actions as $action) {
                        $trusted = "master.items.{$action}";
                        $target = "{$page}.{$action}";

                        if ($role->hasPermissionTo($trusted) && ! $role->hasPermissionTo($target)) {
                            $role->givePermissionTo($target);
                        }
                    }
                }
            });
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            $permissionNames = [];

            foreach ($this->newPermissions as $page => $actions) {
                foreach ($actions as $action) {
                    $permissionNames[] = "{$page}.{$action}";
                }
            }

            Role::query()->whereHas('permissions', fn ($q) => $q->whereIn('name', $permissionNames))
                ->each(fn (Role $role) => $role->revokePermissionTo($permissionNames));

            Permission::query()->whereIn('name', $permissionNames)->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
