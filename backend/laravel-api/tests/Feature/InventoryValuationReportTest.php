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

class InventoryValuationReportTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function receive(float $qty, float $unitCost, string $receivedDate): void
    {
        $this->fifo->receive(
            itemId: $this->item->id,
            warehouseId: $this->warehouse->id,
            qty: $qty,
            unitCost: $unitCost,
            sourceType: StockVoucherType::GOODS_RECEIPT,
            sourceId: (string) Str::uuid(),
            sourceDocumentNumber: 'GR-TEST',
            receivedDate: Carbon::parse($receivedDate),
        );
    }

    private function consumeOn(string $when, float $qty): void
    {
        Carbon::setTestNow(Carbon::parse($when));
        $this->fifo->consume($this->item->id, $this->warehouse->id, $qty, StockVoucherType::DELIVERY, (string) Str::uuid());
        Carbon::setTestNow();
    }

    /**
     * Layer 1 (30 @ 58000) received before the period and partly consumed before it; layer 2
     * (20 @ 60000) received inside the period. One consumption inside the period, one after it
     * (must not count). Verifies Opening + Qty In - Qty Out reconciles exactly to Closing —
     * the whole point of replaying the FIFO audit trail instead of pricing at today's average.
     */
    public function test_computes_opening_in_out_and_closing_with_reconciling_identity(): void
    {
        $this->receive(30, 58000, '2026-01-05');
        $this->consumeOn('2026-01-10', 10);

        $this->receive(20, 60000, '2026-02-15');
        $this->consumeOn('2026-02-20', 5);

        $this->consumeOn('2026-03-05', 3);

        $response = $this->getJson('/api/v1/inventory-valuation?date_from=2026-02-01&date_to=2026-02-28');
        $response->assertOk();

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertEquals(20, $row['opening_qty']);
        $this->assertEquals(20 * 58000, $row['opening_value']);
        $this->assertEquals(20, $row['qty_in']);
        $this->assertEquals(20 * 60000, $row['value_in']);
        $this->assertEquals(5, $row['qty_out']);
        $this->assertEquals(5 * 58000, $row['value_out']);
        $this->assertEquals(35, $row['closing_qty']);
        $this->assertEquals(2070000, $row['closing_value']);
        $this->assertEqualsWithDelta(2070000 / 35, $row['unit_cost'], 0.01);

        $this->assertEqualsWithDelta(
            $row['opening_qty'] + $row['qty_in'] - $row['qty_out'],
            $row['closing_qty'],
            0.0001,
        );

        $summary = $response->json('meta.summary');
        $this->assertEquals(1, $summary['item_count']);
        $this->assertEquals(2070000, $summary['closing_value']);
    }

    public function test_export_returns_a_downloadable_file(): void
    {
        $this->receive(10, 58000, '2026-02-05');

        $response = $this->get('/api/v1/inventory-valuation/export?date_from=2026-02-01&date_to=2026-02-28');
        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
