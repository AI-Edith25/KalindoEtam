<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for the reports.inventory_stock backfill added alongside the Inventory
 * restructure (2026-09-06) — a role that already reached Inventory Movement/Balance or
 * Stock Balance/Ledger must not lose Reports access once those pages are replaced by
 * Reports > Inventory Stock. Mirrors the project's established caution around permission
 * seeder changes (RolePermissionSeeder always re-syncs on deploy).
 */
class RolePermissionSeederInventoryStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_role_with_a_predecessor_permission_is_backfilled_with_reports_inventory_stock_view(): void
    {
        $role = Role::query()->create(['name' => 'Warehouse Staff', 'guard_name' => 'web']);
        \App\Models\Permission::query()->firstOrCreate(['name' => 'reports.inventory_movement.view', 'guard_name' => 'web']);
        $role->givePermissionTo('reports.inventory_movement.view');

        $this->seed(RolePermissionSeeder::class);

        $this->assertTrue($role->fresh()->hasPermissionTo('reports.inventory_stock.view'));
    }

    public function test_a_role_with_none_of_the_predecessor_permissions_is_not_granted_it(): void
    {
        $role = Role::query()->create(['name' => 'Sales Only', 'guard_name' => 'web']);
        \App\Models\Permission::query()->firstOrCreate(['name' => 'sales.orders.view', 'guard_name' => 'web']);
        $role->givePermissionTo('sales.orders.view');

        $this->seed(RolePermissionSeeder::class);

        $this->assertFalse($role->fresh()->hasPermissionTo('reports.inventory_stock.view'));
    }

    public function test_admin_gets_the_new_issue_and_receipt_stock_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = Role::query()->where('name', 'Admin')->sole();

        $this->assertTrue($admin->hasPermissionTo('inventory.issue_stock.view'));
        $this->assertTrue($admin->hasPermissionTo('inventory.receipt_stock.create'));
        $this->assertTrue($admin->hasPermissionTo('reports.inventory_stock.view'));
    }
}
