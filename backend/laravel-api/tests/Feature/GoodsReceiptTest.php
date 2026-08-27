<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Exceptions\OverReceiptConfirmationRequiredException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\PurchaseSetting;
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
 * Covers the 2 Goods Receipt features shipped together: standalone/direct
 * receipts with no source Purchase Order, and the per-Item "Allow
 * Over-Receipt" override on GoodsReceiptService::assertWithinOutstanding().
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

    protected function submittedPurchaseOrder(int $qty, float $rate): \App\Models\PurchaseOrder
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
            'items' => [['item_id' => $this->item->id, 'qty' => 12, 'rate' => 950000]],
        ]);

        $this->assertNull($goodsReceipt->purchase_order_id);
        $this->assertNull($goodsReceipt->items->first()->purchase_order_item_id);
        $this->assertEquals(12, (float) $goodsReceipt->items->first()->qty);

        $goodsReceipt = $this->goodsReceiptService->submit($goodsReceipt->fresh());

        $this->assertEquals('submitted', $goodsReceipt->status->value);
        $this->assertEquals(12, $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id));
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
                'items' => [['purchase_order_item_id' => $purchaseOrder->items->first()->id, 'qty' => 55]],
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
            'items' => [['purchase_order_item_id' => $purchaseOrder->items->first()->id, 'qty' => 55]],
        ]);

        $goodsReceipt = $this->goodsReceiptService->submit($goodsReceipt->fresh());

        $poItem = $purchaseOrder->items->first()->fresh();
        $this->assertEquals(55, (float) $poItem->received_qty);
        $this->assertEquals(-5, (float) $poItem->qty - (float) $poItem->received_qty); // outstanding goes negative, no error
        $this->assertTrue($poItem->received_qty >= $poItem->qty); // "fully received" (PurchaseOrderResource::is_fully_received) still holds past 100%

        // Preserved end to end: GR item, PO received_qty, stock ledger, Item.current_stock.
        $this->assertEquals(55, (float) $goodsReceipt->items->first()->qty);
        $this->assertEquals(55, (float) $this->item->fresh()->current_stock);
        $this->assertEquals(55, (float) StockLedger::query()->where('item_id', $this->item->id)->value('balance_qty'));
    }

    public function test_same_po_item_can_appear_on_two_lines_with_independent_qty(): void
    {
        $purchaseOrder = $this->submittedPurchaseOrder(qty: 100, rate: 1000000);
        $poItemId = $purchaseOrder->items->first()->id;

        $goodsReceipt = $this->goodsReceiptService->create([
            'purchase_order_id' => $purchaseOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [
                ['purchase_order_item_id' => $poItemId, 'qty' => 30],
                ['purchase_order_item_id' => $poItemId, 'qty' => 20],
            ],
        ]);

        $this->assertCount(2, $goodsReceipt->items);
        $qtys = $goodsReceipt->items->map(fn ($line) => (float) $line->qty)->sort()->values()->all();
        $this->assertEquals([20, 30], $qtys);
    }

    public function test_combined_qty_across_lines_for_the_same_po_item_is_validated_not_each_line_alone(): void
    {
        $purchaseOrder = $this->submittedPurchaseOrder(qty: 50, rate: 1000000);
        $poItemId = $purchaseOrder->items->first()->id;

        // Neither line alone exceeds 50, but the combined 30 + 30 = 60 does.
        try {
            $this->goodsReceiptService->create([
                'purchase_order_id' => $purchaseOrder->id,
                'warehouse_id' => $this->warehouse->id,
                'receipt_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'items' => [
                    ['purchase_order_item_id' => $poItemId, 'qty' => 30],
                    ['purchase_order_item_id' => $poItemId, 'qty' => 30],
                ],
            ]);
            $this->fail('Expected the combined qty across both lines to exceed remaining and throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('goods_receipts', 0);

        // Combined 25 + 25 = 50 is exactly the outstanding qty — allowed.
        $goodsReceipt = $this->goodsReceiptService->create([
            'purchase_order_id' => $purchaseOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [
                ['purchase_order_item_id' => $poItemId, 'qty' => 25],
                ['purchase_order_item_id' => $poItemId, 'qty' => 25],
            ],
        ]);

        $this->assertCount(2, $goodsReceipt->items);
    }

    public function test_purchase_order_item_from_a_different_po_is_rejected(): void
    {
        $purchaseOrderA = $this->submittedPurchaseOrder(qty: 50, rate: 1000000);
        $purchaseOrderB = $this->submittedPurchaseOrder(qty: 50, rate: 1000000);
        $foreignPoItemId = $purchaseOrderB->items->first()->id;

        try {
            $this->goodsReceiptService->create([
                'purchase_order_id' => $purchaseOrderA->id,
                'warehouse_id' => $this->warehouse->id,
                'receipt_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'items' => [['purchase_order_item_id' => $foreignPoItemId, 'qty' => 10]],
            ]);
            $this->fail('Expected a PO item belonging to a different Purchase Order to be rejected.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('goods_receipts', 0);
    }

    public function test_unit_category_item_rejects_decimal_qty(): void
    {
        // $this->item defaults to qty_category 'unit' (not set in setUp()).
        try {
            $this->goodsReceiptService->create([
                'purchase_order_id' => null,
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'receipt_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'items' => [['item_id' => $this->item->id, 'qty' => 12.5, 'rate' => 950000]],
            ]);
            $this->fail('Expected a decimal qty on a Unit-category item to throw.');
        } catch (BusinessException) {
        }

        $this->assertDatabaseCount('goods_receipts', 0);
    }

    public function test_weight_category_item_accepts_decimal_qty_and_rounds_to_two_places(): void
    {
        $this->item->update(['qty_category' => 'weight']);

        $goodsReceipt = $this->goodsReceiptService->create([
            'purchase_order_id' => null,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 50.6549, 'rate' => 950000]],
        ]);

        $line = $goodsReceipt->items->first()->fresh();
        $this->assertEquals(50.65, (float) $line->qty);
        $this->assertSame('weight', $line->qty_category->value);

        // (qty * rate) uses the rounded qty, not the raw input.
        $this->assertEquals(50.65 * 950000, (float) $line->amount);
    }

    public function test_weight_category_item_can_exceed_remaining_within_tolerance_and_records_over_receipt_qty(): void
    {
        // Default weight_over_receipt_tolerance_percent is 10 — 50 remaining allows up to +5 unconfirmed.
        $this->item->update(['qty_category' => 'weight']);
        $purchaseOrder = $this->submittedPurchaseOrder(qty: 50, rate: 1000000);

        $goodsReceipt = $this->goodsReceiptService->create([
            'purchase_order_id' => $purchaseOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['purchase_order_item_id' => $purchaseOrder->items->first()->id, 'qty' => 50.3]],
        ]);

        $line = $goodsReceipt->items->first()->fresh();
        $this->assertEquals(50.3, (float) $line->qty);
        $this->assertEquals(0.3, (float) $line->over_receipt_qty);

        $goodsReceipt = $this->goodsReceiptService->submit($goodsReceipt->fresh());
        $poItem = $purchaseOrder->items->first()->fresh();
        $this->assertEquals('submitted', $goodsReceipt->status->value);
        $this->assertEquals(50.3, (float) $poItem->received_qty);
        $this->assertTrue($poItem->received_qty >= $poItem->qty);
    }

    public function test_weight_category_item_beyond_tolerance_requires_confirmation_then_succeeds(): void
    {
        $this->item->update(['qty_category' => 'weight']);
        $purchaseOrder = $this->submittedPurchaseOrder(qty: 50, rate: 1000000);
        $poItemId = $purchaseOrder->items->first()->id;

        // Excess of 10 on a remaining of 50 is 20%, past the default 10% tolerance.
        try {
            $this->goodsReceiptService->create([
                'purchase_order_id' => $purchaseOrder->id,
                'warehouse_id' => $this->warehouse->id,
                'receipt_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'items' => [['purchase_order_item_id' => $poItemId, 'qty' => 60]],
            ]);
            $this->fail('Expected exceeding the tolerance to require confirmation.');
        } catch (OverReceiptConfirmationRequiredException) {
        }

        $this->assertDatabaseCount('goods_receipts', 0);

        $goodsReceipt = $this->goodsReceiptService->create([
            'purchase_order_id' => $purchaseOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'confirm_over_receipt' => true,
            'items' => [['purchase_order_item_id' => $poItemId, 'qty' => 60]],
        ]);

        $line = $goodsReceipt->items->first()->fresh();
        $this->assertEquals(60, (float) $line->qty);
        $this->assertEquals(10, (float) $line->over_receipt_qty);
    }

    public function test_weight_category_item_has_no_limit_when_tolerance_is_zero(): void
    {
        PurchaseSetting::query()->first()->update(['weight_over_receipt_tolerance_percent' => 0]);
        $this->item->update(['qty_category' => 'weight']);
        $purchaseOrder = $this->submittedPurchaseOrder(qty: 50, rate: 1000000);

        $goodsReceipt = $this->goodsReceiptService->create([
            'purchase_order_id' => $purchaseOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['purchase_order_item_id' => $purchaseOrder->items->first()->id, 'qty' => 200]],
        ]);

        $this->assertEquals(200, (float) $goodsReceipt->items->first()->qty);
    }
}
