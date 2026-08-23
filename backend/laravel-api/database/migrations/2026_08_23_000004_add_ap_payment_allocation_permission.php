<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds finance.ap_payment_allocation.{create,update} — the AP mirror of the
 * existing finance.payment_allocation permission (AR side). Purely
 * additive. Grants to any role that already holds
 * finance.outgoing_payment.update — the natural "already trusted with this
 * document" set — so nobody loses the ability to allocate a payment they
 * could already edit/submit. Same idempotent pattern as
 * 2026_08_12_000001_add_finance_general_journal_permission.php.
 */
return new class extends Migration
{
    protected array $permissionNames = [
        'finance.ap_payment_allocation.create',
        'finance.ap_payment_allocation.update',
    ];

    protected string $trustedPermission = 'finance.outgoing_payment.update';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            foreach ($this->permissionNames as $name) {
                $permission = Permission::withTrashed()->firstOrNew(['name' => $name, 'guard_name' => 'web']);

                if ($permission->trashed()) {
                    $permission->restore();
                } elseif (! $permission->exists) {
                    $permission->save();
                }
            }

            Role::query()->with('permissions')->each(function (Role $role) {
                if (! $role->hasPermissionTo($this->trustedPermission)) {
                    return;
                }

                foreach ($this->permissionNames as $name) {
                    if (! $role->hasPermissionTo($name)) {
                        $role->givePermissionTo($name);
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
            foreach ($this->permissionNames as $name) {
                Role::query()->whereHas('permissions', fn ($q) => $q->where('name', $name))
                    ->each(fn (Role $role) => $role->revokePermissionTo($name));

                Permission::query()->where('name', $name)->delete();
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
