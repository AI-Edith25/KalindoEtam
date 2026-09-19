<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for the reports.gross_profit backfill added when Gross Profit split out of
 * Sales Report's old Margin tab (2026-09-19) — a role that already held reports.sales.view (and
 * could therefore already see Margin) must not lose that access once Margin becomes its own
 * top-level report with its own permission. Mirrors RolePermissionSeederInventoryStockTest.
 */
class RolePermissionSeederGrossProfitTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_role_with_reports_sales_view_is_backfilled_with_reports_gross_profit_view(): void
    {
        $role = Role::query()->create(['name' => 'Sales Manager', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'reports.sales.view', 'guard_name' => 'web']);
        $role->givePermissionTo('reports.sales.view');

        $this->seed(RolePermissionSeeder::class);

        $this->assertTrue($role->fresh()->hasPermissionTo('reports.gross_profit.view'));
    }

    public function test_a_role_without_reports_sales_view_is_not_granted_it(): void
    {
        $role = Role::query()->create(['name' => 'Purchase Only', 'guard_name' => 'web']);
        Permission::query()->firstOrCreate(['name' => 'reports.purchase.view', 'guard_name' => 'web']);
        $role->givePermissionTo('reports.purchase.view');

        $this->seed(RolePermissionSeeder::class);

        $this->assertFalse($role->fresh()->hasPermissionTo('reports.gross_profit.view'));
    }

    public function test_admin_gets_the_new_gross_profit_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = Role::query()->where('name', 'Admin')->sole();

        $this->assertTrue($admin->hasPermissionTo('reports.gross_profit.view'));
    }
}
