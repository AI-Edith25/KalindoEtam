<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RolePermissionSeeder extends Seeder
{
    protected array $modules = [
        'company',
        'branch',
        'warehouse',
        'role',
        'user',
        'audit_log',
        'item',
        'stock',
        'item_group',
        'uom',
        'currency',
        'tax',
        'customer',
        'supplier',
        'naming_series',
        'document_attachment',
        'document_timeline',
        'purchase_order',
        'goods_receipt',
        'accounts_payable',
        'sales_order',
        'delivery',
        'invoice',
        'credit_note',
        'debit_note',
        'accounts_receivable',
        'payment_entry',
        'receipt_entry',
        'payment_allocation',
        'chart_of_account',
        'journal_entry',
        'dashboard',
    ];

    protected array $actions = ['view', 'create', 'update', 'delete'];

    /**
     * Modules whose documents can require approval (docs/APPROVAL_WORKFLOW_DESIGN.md
     * §4) — a fifth action, `.approve`, added only for these three, not every
     * module. Same seeding mechanism as $actions above, just a narrower list.
     */
    protected array $approvableModules = ['sales_order', 'purchase_order', 'journal_entry'];

    /**
     * Seed Admin role with full permission set, per D-004: Admin is the
     * first role, other roles are created later through Role & Permission.
     */
    public function run(): void
    {
        $permissionNames = [];

        foreach ($this->modules as $module) {
            foreach ($this->actions as $action) {
                $permissionNames[] = "{$module}.{$action}";
            }
        }

        foreach ($this->approvableModules as $module) {
            $permissionNames[] = "{$module}.approve";
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
