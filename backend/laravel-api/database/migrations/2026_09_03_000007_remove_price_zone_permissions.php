<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Price Zone removal — see 2026_09_03_000004's docblock. Only master.price_zones.* goes away;
 * master.item_prices.* stays (reused verbatim by the Per-Warehouse Pricing endpoints added
 * alongside this same page, see item-warehouse-prices routes in routes/api.php).
 */
return new class extends Migration
{
    protected array $removedPermissions = ['master.price_zones.view', 'master.price_zones.create', 'master.price_zones.update', 'master.price_zones.delete'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            Role::query()->whereHas('permissions', fn ($q) => $q->whereIn('name', $this->removedPermissions))
                ->each(fn (Role $role) => $role->revokePermissionTo($this->removedPermissions));

            Permission::query()->whereIn('name', $this->removedPermissions)->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            foreach ($this->removedPermissions as $name) {
                $permission = Permission::withTrashed()->firstOrNew(['name' => $name, 'guard_name' => 'web']);

                if ($permission->trashed()) {
                    $permission->restore();
                } elseif (! $permission->exists) {
                    $permission->save();
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
