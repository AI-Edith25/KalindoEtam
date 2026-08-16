<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Defensive grant, not a rename — the SO approval action moved from a
 * generic ApprovalFlow request onto its own `sales.orders.approve`
 * permission (see SalesOrderController::approve()). The 2026-08-03 page-
 * scoped remap migration only carries a permission forward to a role if
 * that role already held the *old* `sales_order.approve` name at the time
 * it ran; whether that was ever actually seeded to a role in production is
 * unverifiable from here (no DB access in this environment). To avoid
 * silently locking every role out of the new Approve button, any role that
 * already manages Sales Orders (`sales.orders.update`) is granted
 * `sales.orders.approve` too, if it doesn't have it already.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            Permission::query()->firstOrCreate(['name' => 'sales.orders.approve', 'guard_name' => 'web']);

            Role::query()->with('permissions')->each(function (Role $role) {
                $hasUpdate = $role->permissions->contains('name', 'sales.orders.update');
                $hasApprove = $role->permissions->contains('name', 'sales.orders.approve');

                if ($hasUpdate && ! $hasApprove) {
                    $role->givePermissionTo('sales.orders.approve');
                }
            });
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Deliberately a no-op — this migration only ever adds a permission a role was
        // missing. Revoking it back out on rollback risks stripping access a role picked
        // up legitimately through some other path (e.g. an admin granting it manually
        // between deploys), which is not a safe inverse. Remove it via the Roles UI instead
        // if that's genuinely what's wanted.
    }
};
