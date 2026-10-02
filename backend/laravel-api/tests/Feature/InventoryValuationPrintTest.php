<?php

namespace Tests\Feature;

use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\FifoLayerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Reports > Inventory Stock > Valuation tab's Print button — see InventoryValuationController::print(). */
class InventoryValuationPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'inventory.fifo_layers.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.fifo_layers.view');
        Sanctum::actingAs($user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_print_report_returns_flat_rows_with_location_code_and_item_group(): void
    {
        $warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);
        $itemGroup = ItemGroup::query()->create(['name' => 'Semen']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
        $item = Item::query()->create([
            'item_code' => 'ITM001', 'item_name' => 'Semen Portland 50kg', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 60000,
        ]);

        $fifo = app(FifoLayerService::class);
        $fifo->receive(
            itemId: $item->id, warehouseId: $warehouse->id, qty: 100, unitCost: 50000,
            sourceType: StockVoucherType::GOODS_RECEIPT, sourceId: (string) Str::uuid(),
            sourceDocumentNumber: 'GR-TEST', receivedDate: Carbon::parse('2026-09-01'),
        );

        $response = $this->getJson('/api/v1/inventory-valuation/print?date_from=2026-09-01&date_to=2026-09-30');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data['rows']);
        $row = $data['rows'][0];
        $this->assertSame('ITM001', $row['item_code']);
        $this->assertSame('SMD', $row['warehouse_code']);
        $this->assertSame('Semen', $row['item_group_name']);
        $this->assertEquals(100, $row['closing_qty']);
        $this->assertEquals(5000000, $row['closing_value']);
        $this->assertEquals(5000000, $data['summary']['closing_value']);
    }
}
