<?php

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\ItemGroup;
use App\Models\Supplier;
use App\Models\UnitOfMeasurement;
use App\Services\PurchaseOrderService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Covers Item.qty_category enforcement on Purchase Order lines via QtyCategoryValidator. */
class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    protected PurchaseOrderService $purchaseOrderService;
    protected Supplier $supplier;
    protected \App\Models\Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        $this->purchaseOrderService = app(PurchaseOrderService::class);
        $this->supplier = Supplier::query()->create(['supplier_code' => 'S001', 'supplier_name' => 'Acme Supplier']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Ton']);
        $this->item = \App\Models\Item::query()->create([
            'item_code' => 'CEM-1', 'item_name' => 'Semen Curah', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 1000000,
        ]);
    }

    public function test_unit_category_item_rejects_decimal_qty(): void
    {
        try {
            $this->purchaseOrderService->create([
                'supplier_id' => $this->supplier->id,
                'order_date' => now()->toDateString(),
                'items' => [['item_id' => $this->item->id, 'qty' => 12.5, 'rate' => 950000]],
            ]);
            $this->fail('Expected a decimal qty on a Unit-category item to throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_weight_category_item_accepts_decimal_qty_and_rounds_to_two_places(): void
    {
        $this->item->update(['qty_category' => 'weight']);

        $purchaseOrder = $this->purchaseOrderService->create([
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 50.6549, 'rate' => 950000]],
        ]);

        $line = $purchaseOrder->items->first()->fresh();
        $this->assertEquals(50.65, (float) $line->qty);
        $this->assertSame('weight', $line->qty_category->value);
        $this->assertEquals(50.65 * 950000, (float) $line->amount);
    }
}
