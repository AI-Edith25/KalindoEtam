<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\StockAdjustmentService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Covers Item.qty_category enforcement on Stock Adjustment lines via QtyCategoryValidator. */
class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected StockAdjustmentService $stockAdjustmentService;
    protected Warehouse $warehouse;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        $this->stockAdjustmentService = app(StockAdjustmentService::class);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Ton']);
        $this->item = Item::query()->create([
            'item_code' => 'CEM-1', 'item_name' => 'Semen Curah', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 1000000,
        ]);
    }

    public function test_unit_category_item_rejects_decimal_counted_qty(): void
    {
        try {
            $this->stockAdjustmentService->create([
                'warehouse_id' => $this->warehouse->id,
                'adjustment_date' => now()->toDateString(),
                'items' => [['item_id' => $this->item->id, 'counted_qty' => 12.5, 'reason' => 'Physical count']],
            ]);
            $this->fail('Expected a decimal counted_qty on a Unit-category item to throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('stock_adjustments', 0);
    }

    public function test_weight_category_item_accepts_decimal_counted_qty_and_rounds_to_two_places(): void
    {
        $this->item->update(['qty_category' => 'weight']);

        $adjustment = $this->stockAdjustmentService->create([
            'warehouse_id' => $this->warehouse->id,
            'adjustment_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'counted_qty' => 50.6549, 'reason' => 'Physical count']],
        ]);

        $line = $adjustment->items->first()->fresh();
        $this->assertEquals(50.65, (float) $line->counted_qty);
        $this->assertSame('weight', $line->qty_category->value);
    }
}
