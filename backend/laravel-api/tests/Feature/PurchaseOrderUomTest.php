<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FifoLayer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\GoodsReceiptService;
use App\Services\ItemService;
use App\Services\PurchaseOrderService;
use App\Services\StockLedgerService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A PO line can be in one of its Item's UOMs; Goods Receipt converts to base qty/cost at the stock boundary. */
class PurchaseOrderUomTest extends TestCase
{
    use RefreshDatabase;

    protected PurchaseOrderService $purchaseOrderService;
    protected GoodsReceiptService $goodsReceiptService;
    protected StockLedgerService $stockLedgerService;
    protected Supplier $supplier;
    protected Warehouse $warehouse;
    protected Item $item;
    protected UnitOfMeasurement $dus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->purchaseOrderService = app(PurchaseOrderService::class);
        $this->goodsReceiptService = app(GoodsReceiptService::class);
        $this->stockLedgerService = app(StockLedgerService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->supplier = Supplier::query()->create(['supplier_code' => 'S001', 'supplier_name' => 'Acme Supplier']);

        $kg = UnitOfMeasurement::query()->create(['name' => 'KG']);
        $this->dus = UnitOfMeasurement::query()->create(['name' => 'DUS']);
        $this->item = app(ItemService::class)->create([
            'item_code' => 'PKU-1', 'item_name' => 'Paku', 'item_group_id' => ItemGroup::query()->create(['name' => 'General'])->id,
            'uom_id' => $kg->id, 'standard_rate' => 20000, 'qty_category' => 'weight',
            'uoms' => [['uom_id' => $this->dus->id, 'conversion_factor' => 25]],
        ]);
    }

    protected function submittedPo(array $line): PurchaseOrder
    {
        $purchaseOrder = $this->purchaseOrderService->create([
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [array_merge(['item_id' => $this->item->id], $line)],
        ]);
        $this->approveDocument($purchaseOrder);

        return $this->purchaseOrderService->submit($purchaseOrder);
    }

    protected function receive(PurchaseOrder $purchaseOrder, float $qty): \App\Models\GoodsReceipt
    {
        $goodsReceipt = $this->goodsReceiptService->create([
            'purchase_order_id' => $purchaseOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'items' => [['purchase_order_item_id' => $purchaseOrder->items->first()->id, 'qty' => $qty]],
        ]);

        return $this->goodsReceiptService->submit($goodsReceipt->fresh());
    }

    public function test_po_line_uom_and_factor_are_saved_and_amount_is_unconverted(): void
    {
        $purchaseOrder = $this->submittedPo(['uom_id' => $this->dus->id, 'qty' => 10, 'rate' => 500000]);

        $line = $purchaseOrder->items->first();
        $this->assertSame($this->dus->id, $line->uom_id);
        $this->assertEquals(25, (float) $line->uom_factor);
        $this->assertEquals(5000000, (float) $line->amount);
    }

    public function test_goods_receipt_converts_stock_and_fifo_cost_to_base_unit(): void
    {
        $purchaseOrder = $this->submittedPo(['uom_id' => $this->dus->id, 'qty' => 10, 'rate' => 500000]);

        $goodsReceipt = $this->receive($purchaseOrder, 10);

        $receiptLine = $goodsReceipt->items->first();
        $this->assertSame('DUS', $receiptLine->uom);
        $this->assertEquals(5000000, (float) $receiptLine->amount);

        $this->assertEquals(250, $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id));

        $layer = FifoLayer::query()->where('source_id', $goodsReceipt->id)->sole();
        $this->assertEquals(250, (float) $layer->qty_in);
        $this->assertEquals(20000, (float) $layer->unit_cost);

        // Received/outstanding stay in the PO line's own UOM.
        $this->assertEquals(10, (float) $purchaseOrder->items()->first()->received_qty);
    }

    public function test_po_without_uom_keeps_base_behaviour(): void
    {
        $purchaseOrder = $this->submittedPo(['qty' => 40, 'rate' => 20000]);

        $this->assertNull($purchaseOrder->items->first()->uom_id);

        $this->receive($purchaseOrder, 40);

        $this->assertEquals(40, $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id));
    }

    public function test_uom_not_belonging_to_the_item_is_rejected(): void
    {
        $ton = UnitOfMeasurement::query()->create(['name' => 'TON']);

        $this->expectException(BusinessException::class);

        $this->purchaseOrderService->create([
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'uom_id' => $ton->id, 'qty' => 1, 'rate' => 1000]],
        ]);
    }

    public function test_changing_item_factor_later_does_not_change_an_existing_po_line(): void
    {
        $purchaseOrder = $this->submittedPo(['uom_id' => $this->dus->id, 'qty' => 2, 'rate' => 500000]);

        app(ItemService::class)->update($this->item->fresh(), ['uoms' => [['uom_id' => $this->dus->id, 'conversion_factor' => 50]]]);

        $this->receive($purchaseOrder, 2);

        $this->assertEquals(50, $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id));
    }
}
