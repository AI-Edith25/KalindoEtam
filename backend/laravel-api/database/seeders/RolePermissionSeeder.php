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
        'master.items' => ['view', 'create', 'update', 'delete'],
        'master.item_groups' => ['view', 'create', 'update', 'delete'],
        'master.uoms' => ['view', 'create', 'update', 'delete'],
        'master.currencies' => ['view', 'create', 'update', 'delete'],
        'master.taxes' => ['view', 'create', 'update', 'delete'],
        'master.customers' => ['view', 'create', 'update', 'delete'],
        'master.suppliers' => ['view', 'create', 'update', 'delete'],
        'master.warehouses' => ['view', 'create', 'update', 'delete'],
        'master.chart_of_accounts' => ['view', 'create', 'update', 'delete'],
        'inventory.stock_balance' => ['view'],
        'inventory.stock_ledger' => ['view', 'create'],
        'inventory.adjustments' => ['view', 'create', 'update', 'delete'],
        'purchase.orders' => ['view', 'create', 'update', 'delete', 'approve'],
        'purchase.goods_receipts' => ['view', 'create', 'update', 'delete'],
        'sales.orders' => ['view', 'create', 'update', 'delete', 'approve'],
        'sales.deliveries' => ['view', 'create', 'update', 'delete'],
        'sales.invoices' => ['view', 'create', 'update', 'delete'],
        'sales.credit_notes' => ['view', 'create', 'update', 'delete'],
        'sales.debit_notes' => ['view', 'create', 'update', 'delete'],
        'finance.outgoing_payment' => ['view', 'create', 'update', 'delete'],
        'finance.incoming_payment' => ['view', 'create', 'update', 'delete'],
        'finance.accounts_payable' => ['view'],
        'finance.accounts_receivable' => ['view'],
        'finance.payment_allocation' => ['create', 'update'],
        'accounting.journal_entries' => ['view', 'create', 'update', 'delete', 'approve'],
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
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
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
    }
}
