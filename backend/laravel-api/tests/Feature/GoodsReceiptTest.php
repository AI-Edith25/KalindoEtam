<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\StockLedger;
use App\Models\Supplier;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseOrderService;
use App\Services\StockLedgerService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the 3 Goods Receipt bugs fixed together: standalone/direct receipts
 * with no source Purchase Order, the per-Item "Allow Over-Receipt" override
 * on GoodsReceiptService::assertWithinOutstanding(), and 2-decimal-precision
 * quantities (bulk items like cement are received by truck-scale weight).
 */
class GoodsReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected PurchaseOrderService $purchaseOrderService;
    protected GoodsReceiptService $goodsReceiptService;
    protected StockLedgerService $stockLedgerService;
    protected Supplier $supplier;
    protected Warehouse $warehouse;
    protected Item $item;

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

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Ton']);
        $this->item = Item::query()->create([
            'item_code' => 'CEM-1', 'item_name' => 'Semen Curah', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 1000000,
        ]);
    }

    protected function submittedPurchaseOrder(float $qty, float $rate): \App\Models\PurchaseOrder
    {
        $purchaseOrder = $this->purchaseOrderService->create([
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate]],
        ]);
        $this->approveDocument($purchaseOrder);

        return $this->purchaseOrderService->submit($purchaseOrder);
    }

    public function test_creates_and_submits_a_direct_receipt_with_no_purchase_order(): void
    {
        $goodsReceipt = $this->goodsReceiptService->create([
            'purchase_order_id' => null,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 12.5, 'rate' => 950000]],
        ]);

        $this->assertNull($goodsReceipt->purchase_order_id);
        $this->assertNull($goodsReceipt->items->first()->purchase_order_item_id);
        $this->assertEquals(12.5, (float) $goodsReceipt->items->first()->qty);

        $goodsReceipt = $this->goodsReceiptService->submit($goodsReceipt->fresh());

        $this->assertEquals('submitted', $goodsReceipt->status->value);
        $this->assertEquals(12.5, $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id));
        $this->assertDatabaseCount('stock_ledgers', 1);
    }

    public function test_over_receipt_is_still_blocked_by_default(): void
    {
        $purchaseOrder = $this->submittedPurchaseOrder(qty: 50, rate: 1000000);

        try {
            $this->goodsReceiptService->create([
                'purchase_order_id' => $purchaseOrder->id,
                'warehouse_id' => $this->warehouse->id,
                'receipt_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'items' => [['purchase_order_item_id' => $purchaseOrder->items->first()->id, 'qty' => 50.65]],
            ]);
            $this->fail('Expected receiving more than the PO qty to throw when allow_over_receipt is false.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('goods_receipts', 0);
    }

    public function test_over_receipt_is_allowed_when_the_item_opts_in(): void
    {
        $this->item->update(['allow_over_receipt' => true]);
        $purchaseOrder = $this->submittedPurchaseOrder(qty: 50, rate: 1000000);

        $goodsReceipt = $this->goodsReceiptService->create([
            'purchase_order_id' => $purchaseOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['purchase_order_item_id' => $purchaseOrder->items->first()->id, 'qty' => 50.65]],
        ]);

        $goodsReceipt = $this->goodsReceiptService->submit($goodsReceipt->fresh());

        $poItem = $purchaseOrder->items->first()->fresh();
        $this->assertEquals(50.65, (float) $poItem->received_qty);
        $this->assertEqualsWithDelta(-0.65, (float) $poItem->qty - (float) $poItem->received_qty, 0.001); // outstanding goes negative, no error
        $this->assertTrue((float) $poItem->received_qty >= (float) $poItem->qty); // "fully received" (PurchaseOrderResource::is_fully_received) still holds past 100%

        // Decimal precision preserved end to end: GR item, PO received_qty, stock ledger, Item.current_stock.
        $this->assertEquals(50.65, (float) $goodsReceipt->items->first()->qty);
        $this->assertEquals(50.65, (float) $this->item->fresh()->current_stock);
        $this->assertEquals(50.65, (float) StockLedger::query()->where('item_id', $this->item->id)->value('balance_qty'));
    }
}
