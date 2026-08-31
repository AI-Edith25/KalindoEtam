<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds master.item_groups.import — Import Wizard, Phase D (Item Groups).
 * Same idempotent pattern as 2026_08_31_000002_add_master_items_import_permission.php.
 */
return new class extends Migration
{
    protected string $permissionName = 'master.item_groups.import';

    protected string $trustedPermission = 'master.item_groups.create';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            $permission = Permission::withTrashed()->firstOrNew(['name' => $this->permissionName, 'guard_name' => 'web']);

            if ($permission->trashed()) {
                $permission->restore();
            } elseif (! $permission->exists) {
                $permission->save();
            }

            Role::query()->with('permissions')->each(function (Role $role) {
                if ($role->hasPermissionTo($this->trustedPermission) && ! $role->hasPermissionTo($this->permissionName)) {
                    $role->givePermissionTo($this->permissionName);
                }
            });
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            Role::query()->whereHas('permissions', fn ($q) => $q->where('name', $this->permissionName))
                ->each(fn (Role $role) => $role->revokePermissionTo($this->permissionName));

            Permission::query()->where('name', $this->permissionName)->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
