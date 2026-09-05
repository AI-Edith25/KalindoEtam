<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RolePermissionSeeder extends Seeder
{
    /**
     * Every page-scoped permission, `{group}.{page}` => actions. Group matches
     * the frontend's navTree.ts top-level nav section, page matches its
     * navTree page key — permissions are named after the application page
     * that owns them, never a shared backend resource, so two pages that
     * happen to read the same resource (e.g. Sales > Deliveries and
     * Reports > Delivery both read /deliveries) never share a permission.
     * `reports.*` is the one group with no writes — read-only by design.
     */
    protected array $pages = [
        'administration.company' => ['view', 'create', 'update', 'delete'],
        'administration.branch' => ['view', 'create', 'update', 'delete'],
        'administration.roles' => ['view', 'create', 'update', 'delete'],
        'administration.users' => ['view', 'create', 'update'],
        'administration.audit_log' => ['view'],
        'administration.naming_series' => ['view', 'create', 'update', 'delete'],
        'administration.purchase_settings' => ['view', 'update'],
        'master.items' => ['view', 'create', 'update', 'delete', 'import'],
        'master.item_groups' => ['view', 'create', 'update', 'delete', 'import'],
        'master.uoms' => ['view', 'create', 'update', 'delete', 'import'],
        'master.currencies' => ['view', 'create', 'update', 'delete'],
        'master.taxes' => ['view', 'create', 'update', 'delete'],
        'master.customers' => ['view', 'create', 'update', 'delete', 'import'],
        'master.suppliers' => ['view', 'create', 'update', 'delete', 'import'],
        'master.sales_persons' => ['view', 'create', 'update', 'delete', 'import'],
        'master.sales_targets' => ['view', 'create', 'update', 'delete'],
        'master.terms_of_payment' => ['view', 'create', 'update', 'delete'],
        'master.warehouses' => ['view', 'create', 'update', 'delete', 'import'],
        'master.item_prices' => ['view', 'create', 'update', 'delete', 'import'],
        // Neither a CRUD page of its own — Terms of Payment's import permission is named
        // after its import-route module slug ("terms-of-payments", plural), which doesn't
        // match "master.terms_of_payment" (singular) used by this page's own view/create/
        // update/delete above — a pre-existing singular/plural inconsistency in this app
        // (the CRUD route itself is also plural: Route::apiResource('terms-of-payments', ...)).
        // Item Standard Rates has no CRUD page at all — it's the Item Prices page's second
        // import action (updates items.standard_rate from a legacy price file, see
        // ItemStandardRateImportTemplate).
        'master.terms_of_payments' => ['import'],
        'master.item_standard_rates' => ['import'],
        'master.chart_of_accounts' => ['view', 'create', 'update', 'delete'],
        'master.miscellaneous' => ['view', 'create', 'update', 'delete', 'import'],
        'inventory.stock_balance' => ['view'],
        'inventory.stock_ledger' => ['view', 'create'],
        'inventory.adjustments' => ['view', 'create', 'update', 'delete'],
        'inventory.transfers' => ['view', 'create', 'update', 'delete'],
        'purchase.orders' => ['view', 'create', 'update', 'delete', 'approve'],
        'purchase.goods_receipts' => ['view', 'create', 'update', 'delete'],
        'purchase.invoices' => ['view', 'create', 'update', 'delete'],
        'purchase.returns' => ['view', 'create', 'update', 'delete'],
        'sales.orders' => ['view', 'create', 'update', 'delete', 'approve', 'override_credit_check'],
        'sales.deliveries' => ['view', 'create', 'update', 'delete'],
        'sales.invoices' => ['view', 'create', 'update', 'delete', 'approve'],
        'sales.credit_notes' => ['view', 'create', 'update', 'delete'],
        'sales.debit_notes' => ['view', 'create', 'update', 'delete'],
        'finance.outgoing_payment' => ['view', 'create', 'update', 'delete'],
        'finance.incoming_payment' => ['view', 'create', 'update', 'delete'],
        'finance.general_journal' => ['view'],
        'finance.accounts_payable' => ['view'],
        'finance.accounts_receivable' => ['view'],
        'finance.payment_allocation' => ['create', 'update'],
        'finance.ap_payment_allocation' => ['create', 'update'],
        'accounting.journal_entries' => ['view', 'create', 'update', 'delete', 'approve'],
        'accounting.journal_list' => ['view'],
        'accounting.general_ledger' => ['view'],
        'accounting.trial_balance' => ['view'],
        'accounting.profit_loss' => ['view'],
        'accounting.balance_sheet' => ['view'],
        'accounting.cash_flow' => ['view'],
        'accounting.period_closing' => ['view', 'create', 'update'],
        'reports.purchase' => ['view'],
        'reports.goods_receipts' => ['view'],
        'reports.sales' => ['view'],
        'reports.deliveries' => ['view'],
        'reports.inventory_movement' => ['view'],
        'reports.inventory_balance' => ['view'],
        'reports.ar_detail' => ['view'],
        'reports.tanda_terima_invoice' => ['view'],
        'reports.penagihan_harian' => ['view'],
        'system.document_attachment' => ['view', 'create', 'delete'],
        'system.document_timeline' => ['view'],
    ];

    /** Not tied to any page — a single flat permission each. */
    protected array $standalonePermissions = ['dashboard.view'];

    /**
     * Seed Admin role with full permission set, per D-004: Admin is the
     * first role, other roles are created later through Role & Permission.
     */
    public function run(): void
    {
        $permissionNames = $this->standalonePermissions;

        foreach ($this->pages as $page => $actions) {
            foreach ($actions as $action) {
                $permissionNames[] = "{$page}.{$action}";
            }
        }

        foreach ($permissionNames as $name) {
            // Permission has SoftDeletes, and the (name, guard_name) unique index doesn't
            // exempt trashed rows — a plain firstOrCreate() can't see a soft-deleted row
            // (Eloquent's default scope excludes it) but the INSERT it then attempts still
            // collides with it, throwing a duplicate-key error instead of finding it. Any
            // permission previously soft-deleted (e.g. by a rename migration later reverted)
            // must be looked up including trashed and restored, not blindly re-created.
            $permission = Permission::withTrashed()->firstOrNew(['name' => $name, 'guard_name' => 'web']);

            if ($permission->trashed()) {
                $permission->restore();
            } elseif (! $permission->exists) {
                $permission->save();
            }
        }

        $adminRole = Role::query()->firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions($permissionNames);

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Admin', 'password' => Hash::make('password')]
        );

        if (! $admin->hasRole('Admin')) {
            $admin->assignRole($adminRole);
        }

        // finance.general_journal.view is the new entry point into the same 8 report pages
        // Accounting Reports already gated individually — any role that could already reach
        // at least one of them keeps that access, additive only, nothing else on the role changes.
        $accountingViewPermissions = collect($this->pages)
            ->keys()
            ->filter(fn (string $page) => str_starts_with($page, 'accounting.'))
            ->map(fn (string $page) => "{$page}.view");

        Role::query()->where('name', '!=', 'Admin')->with('permissions')->each(function (Role $role) use ($accountingViewPermissions) {
            $hasAccountingAccess = $role->permissions->pluck('name')->intersect($accountingViewPermissions)->isNotEmpty();

            if ($hasAccountingAccess && ! $role->hasPermissionTo('finance.general_journal.view')) {
                $role->givePermissionTo('finance.general_journal.view');
            }
        });
    }
}
