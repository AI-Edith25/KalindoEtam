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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FifoValuationReportTest extends TestCase
{
    use RefreshDatabase;

    protected FifoLayerService $fifo;

    protected Warehouse $warehouse;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::query()->firstOrCreate(['name' => 'inventory.fifo_layers.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.fifo_layers.view');
        Sanctum::actingAs($user);

        $this->fifo = app(FifoLayerService::class);
        $this->warehouse = Warehouse::query()->create(['name' => 'Samarinda', 'code' => 'SMD', 'warehouse_type' => WarehouseType::MAIN]);
        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM001', 'item_name' => 'Semen Portland 50kg', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 60000,
        ]);
    }

    private function receive(float $qty, float $unitCost): void
    {
        $this->fifo->receive(
            itemId: $this->item->id,
            warehouseId: $this->warehouse->id,
            qty: $qty,
            unitCost: $unitCost,
            sourceType: StockVoucherType::GOODS_RECEIPT,
            sourceId: (string) Str::uuid(),
            sourceDocumentNumber: 'GR-TEST',
            receivedDate: now(),
        );
    }

    public function test_groups_layers_by_item_and_warehouse_with_weighted_average_and_summary(): void
    {
        $this->receive(30, 58000);
        $this->receive(20, 60000);

        $response = $this->getJson('/api/v1/fifo-layers');
        $response->assertOk();

        $groups = $response->json('data');
        $this->assertCount(1, $groups);
        $this->assertEquals(50, $groups[0]['qty_remaining']);
        $this->assertEquals(30 * 58000 + 20 * 60000, $groups[0]['total_value']);
        $this->assertEqualsWithDelta((30 * 58000 + 20 * 60000) / 50, $groups[0]['weighted_average_cost'], 0.01);
        $this->assertCount(2, $groups[0]['layers']);

        $summary = $response->json('meta.summary');
        $this->assertEquals(1, $summary['item_count']);
        $this->assertEquals(50, $summary['total_qty']);
        $this->assertEquals(30 * 58000 + 20 * 60000, $summary['total_value']);
    }

    public function test_hide_exhausted_excludes_a_fully_consumed_layer(): void
    {
        $this->receive(10, 58000);
        $this->fifo->consume($this->item->id, $this->warehouse->id, 10, StockVoucherType::DELIVERY, (string) Str::uuid());

        $shown = $this->getJson('/api/v1/fifo-layers');
        $this->assertCount(1, $shown->json('data'));

        $hidden = $this->getJson('/api/v1/fifo-layers?hide_exhausted=true');
        $this->assertCount(0, $hidden->json('data'));
    }

    public function test_export_returns_a_downloadable_file(): void
    {
        $this->receive(10, 58000);

        $response = $this->get('/api/v1/fifo-layers/export');
        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
