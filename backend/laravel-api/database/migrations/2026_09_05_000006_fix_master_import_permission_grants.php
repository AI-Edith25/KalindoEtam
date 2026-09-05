<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * The 5 migrations right before this one (2026_09_05_000001-000005) each
 * conditionally granted their new `master.{module}.import` permission only
 * to a role that already had that module's own `.create` permission — the
 * same pattern the working 2026_08_31 Items/Item Groups/UOMs import
 * migrations use. In production this silently granted nothing: Admin
 * didn't yet have (e.g.) `master.terms_of_payment.create` at the moment
 * those migrations ran in that deploy, so the conditional check failed —
 * a one-time migration never gets a second chance to notice the role
 * caught up moments later.
 *
 * Fix: grant these 5 import permissions to every role that already has
 * `master.items.import` (proven reliably granted, unconditional — sidesteps
 * the same fragile per-module timing dependency instead of trying to guess
 * a "trusted" permission name again).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'master.terms_of_payments.import',
        'master.warehouses.import',
        'master.suppliers.import',
        'master.customers.import',
        'master.item_standard_rates.import',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            Role::query()->with('permissions')->each(function (Role $role) {
                if (! $role->hasPermissionTo('master.items.import')) {
                    return;
                }

                foreach (self::PERMISSIONS as $permission) {
                    if (! $role->hasPermissionTo($permission)) {
                        $role->givePermissionTo($permission);
                    }
                }
            });
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Intentionally a no-op — reverting would strip these grants even from
        // a role that has since started relying on them for legitimate use;
        // the 5 preceding migrations already own deleting the permission
        // records themselves on rollback.
    }
};
