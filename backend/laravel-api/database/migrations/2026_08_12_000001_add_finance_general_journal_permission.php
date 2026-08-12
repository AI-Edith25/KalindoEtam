<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds `finance.general_journal.view` — the new chooser page that sits in front of the
 * 8 existing Accounting Reports pages (Finance > General Journal). Purely additive: no
 * existing permission is renamed, moved, or deleted, unlike the 2026-08-10 move+revert
 * that soft-deleted `accounting.journal_entries.*` and collided with it on reseed. Grants
 * the new permission to any role that already holds view on at least one `accounting.*`
 * page, so nobody loses their entry point into those reports on deploy.
 */
return new class extends Migration
{
    protected string $permissionName = 'finance.general_journal.view';

    protected array $accountingViewPermissions = [
        'accounting.journal_entries.view',
        'accounting.journal_list.view',
        'accounting.general_ledger.view',
        'accounting.trial_balance.view',
        'accounting.profit_loss.view',
        'accounting.balance_sheet.view',
        'accounting.cash_flow.view',
        'accounting.period_closing.view',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            // Same idempotent lookup as RolePermissionSeeder — withTrashed()->firstOrNew()+restore()
            // rather than firstOrCreate(), so re-running this after a soft-delete never 1062s.
            $permission = Permission::withTrashed()->firstOrNew(['name' => $this->permissionName, 'guard_name' => 'web']);

            if ($permission->trashed()) {
                $permission->restore();
            } elseif (! $permission->exists) {
                $permission->save();
            }

            Role::query()->with('permissions')->each(function (Role $role) {
                $hasAccountingAccess = $role->permissions->pluck('name')
                    ->intersect($this->accountingViewPermissions)
                    ->isNotEmpty();

                if ($hasAccountingAccess && ! $role->hasPermissionTo($this->permissionName)) {
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

            // Nothing else will ever reuse this name, so soft-deleting it here can't collide
            // with a later reseed the way the accounting.journal_entries.* rename did.
            Permission::query()->where('name', $this->permissionName)->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
