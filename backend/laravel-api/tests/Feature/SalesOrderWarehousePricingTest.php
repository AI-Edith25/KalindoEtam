<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\ItemWarehousePrice;
use App\Models\Permission;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sales Order now carries its own warehouse_id (chosen before Delivery even exists), used to
 * resolve each line's rate via ItemController::index?warehouse_id=... -> ItemPriceResolver.
 */
class SalesOrderWarehousePricingTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected Warehouse $main;

    protected Customer $customer;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        foreach (['view', 'create'] as $action) {
            Permission::query()->firstOrCreate(['name' => "sales.orders.{$action}", 'guard_name' => 'web']);
        }
        Permission::query()->firstOrCreate(['name' => 'master.items.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo(['sales.orders.view', 'sales.orders.create', 'master.items.view']);
        Sanctum::actingAs($user);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $this->branch = Branch::query()->create(['company_id' => $company->id, 'name' => 'HQ', 'code' => 'HQ']);
        $this->main = Warehouse::query()->create(['name' => 'Balikpapan', 'code' => 'BPP', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM-1', 'item_name' => 'Widget',
            'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 10000,
        ]);
    }

    public function test_creating_a_sales_order_without_warehouse_id_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/sales-orders', [
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 10000]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('warehouse_id');
    }

    public function test_creating_a_sales_order_with_warehouse_id_persists_it(): void
    {
        $response = $this->postJson('/api/v1/sales-orders', [
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => $this->main->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 10000]],
        ]);

        $response->assertCreated();
        $this->assertSame($this->main->id, $response->json('data.warehouse_id'));
        $this->assertDatabaseHas('sales_orders', ['id' => $response->json('data.id'), 'warehouse_id' => $this->main->id]);
    }

    public function test_items_lookup_with_the_orders_warehouse_reflects_the_warehouse_override(): void
    {
        ItemWarehousePrice::query()->create(['item_id' => $this->item->id, 'warehouse_id' => $this->main->id, 'rate' => 12000]);

        $response = $this->getJson("/api/v1/items?warehouse_id={$this->main->id}");

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $this->item->id);
        $this->assertSame('12000.00', $row['effective_rate']);
    }
}
