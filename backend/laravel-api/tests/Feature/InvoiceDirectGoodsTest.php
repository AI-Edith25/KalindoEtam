<?php

namespace Tests\Feature;

use App\Enums\InvoiceType;
use App\Enums\QtyCategory;
use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FifoLayer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\StockLedger;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\SalesOrderService;
use App\Services\StockLedgerService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Goods (Direct)" — Jumbo & Curah billed straight to a Customer, no Sales Order/Delivery, but still reduces real stock. See InvoiceService::createDirectGoods()/postDirectGoodsStock()/reverseDirectGoodsStock(). */
class InvoiceDirectGoodsTest extends TestCase
{
    use RefreshDatabase;

    protected InvoiceService $invoiceService;
    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected StockLedgerService $stockLedgerService;
    protected Customer $customer;
    protected Branch $branch;
    protected Warehouse $warehouse;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->invoiceService = app(InvoiceService::class);
        $this->salesOrderService = app(SalesOrderService::class);
        $this->deliveryService = app(DeliveryService::class);
        $this->stockLedgerService = app(StockLedgerService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $this->branch = Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM-1', 'item_name' => 'Semen 50kg', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 60000,
        ]);

        $this->seedStock($this->item->id, $this->warehouse->id, 100, unitCost: 58000);
    }

    protected function createDirectGoodsInvoice(int $qty = 10, float $rate = 60000): \App\Models\Invoice
    {
        return $this->invoiceService->create([
            'invoice_type' => InvoiceType::GOODS->value,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate]],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    protected function submittedGoodsInvoiceViaDelivery(): \App\Models\Invoice
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => 60000]],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->approve($salesOrder);

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => 5]],
        ]);
        $delivery = $this->deliveryService->complete($delivery);

        return $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    public function test_direct_goods_invoice_shares_the_invoice_goods_naming_series_and_carries_no_delivery_or_sales_order(): void
    {
        $deliveryBased = $this->submittedGoodsInvoiceViaDelivery();
        $direct = $this->createDirectGoodsInvoice();

        $this->assertStringStartsWith('SI/KE/', $deliveryBased->document_number);
        $this->assertStringStartsWith('SI/KE/', $direct->document_number);

        $deliveryCounter = (int) explode('/', $deliveryBased->document_number)[2];
        $directCounter = (int) explode('/', $direct->document_number)[2];
        $this->assertSame($deliveryCounter + 1, $directCounter, 'Direct Goods must share one sequence with Delivery-based Goods invoices.');

        $this->assertNull($direct->delivery_id);
        $this->assertNull($direct->sales_order_id);
        $this->assertSame($this->warehouse->id, $direct->warehouse_id);
        $this->assertTrue($direct->isDirectGoods());
        $this->assertSame(InvoiceType::GOODS, $direct->invoice_type);
    }

    public function test_creating_a_direct_goods_invoice_does_not_touch_stock(): void
    {
        $before = $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id);

        $this->createDirectGoodsInvoice(qty: 10);

        $after = $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id);
        $this->assertSame($before, $after);
        $this->assertDatabaseMissing('stock_ledgers', ['voucher_type' => StockVoucherType::DIRECT_INVOICE->value]);
    }

    public function test_submitting_a_direct_goods_invoice_reduces_stock_tags_the_ledger_and_consumes_fifo(): void
    {
        $invoice = $this->createDirectGoodsInvoice(qty: 10, rate: 60000);
        $before = $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id);

        $invoice = $this->invoiceService->submit($invoice);

        $after = $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id);
        $this->assertEquals($before - 10, $after);

        $ledgerRow = StockLedger::query()->where('voucher_type', StockVoucherType::DIRECT_INVOICE)->where('voucher_id', $invoice->id)->firstOrFail();
        $this->assertEquals(-10, (float) $ledgerRow->qty_change);
        $this->assertSame($this->warehouse->id, $ledgerRow->warehouse_id);

        $layer = FifoLayer::query()->where('item_id', $this->item->id)->where('warehouse_id', $this->warehouse->id)->firstOrFail();
        $this->assertEquals(90, (float) $layer->qty_remaining); // 100 seeded - 10 consumed

        $line = $invoice->items->first();
        $this->assertEquals(58000, (float) $line->unit_cost);
        $this->assertEquals(58000 * 10, (float) $line->cost_amount);
    }

    public function test_submitting_a_direct_goods_invoice_that_exceeds_available_stock_is_blocked_and_writes_nothing(): void
    {
        $invoice = $this->createDirectGoodsInvoice(qty: 999, rate: 60000);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessageMatches('/Insufficient stock/');

        try {
            $this->invoiceService->submit($invoice);
        } finally {
            $this->assertDatabaseMissing('stock_ledgers', ['voucher_type' => StockVoucherType::DIRECT_INVOICE->value]);
            $this->assertSame('draft', $invoice->fresh()->status->value);
        }
    }

    public function test_cancelling_a_submitted_direct_goods_invoice_reverses_the_stock_and_restores_the_fifo_layer(): void
    {
        $invoice = $this->createDirectGoodsInvoice(qty: 10, rate: 60000);
        $invoice = $this->invoiceService->submit($invoice);
        $balanceAfterSubmit = $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id);

        $invoice = $this->invoiceService->cancel($invoice);

        $balanceAfterCancel = $this->stockLedgerService->getCurrentBalance($this->item->id, $this->warehouse->id);
        $this->assertEquals($balanceAfterSubmit + 10, $balanceAfterCancel);
        $this->assertEquals(100, $balanceAfterCancel); // back to the original seeded balance

        $reversalRow = StockLedger::query()->where('voucher_type', StockVoucherType::DIRECT_INVOICE)->where('voucher_id', $invoice->id)->where('qty_change', '>', 0)->firstOrFail();
        $this->assertEquals(10, (float) $reversalRow->qty_change);

        $layer = FifoLayer::query()->where('item_id', $this->item->id)->where('warehouse_id', $this->warehouse->id)->firstOrFail();
        $this->assertEquals(100, (float) $layer->qty_remaining); // fully restored

        $this->assertNull($invoice->accountsReceivable);
        $this->assertSame('cancelled', $invoice->status->value);
    }

    /** Loose/jumbo cement billed straight to a Customer by truck-scale weight — the exact scenario this invoice_items.qty widening (2026-10-05) exists for. */
    public function test_direct_goods_invoice_accepts_decimal_qty_for_a_weight_category_item(): void
    {
        $weightItem = Item::query()->create([
            'item_code' => 'ITM-CURAH', 'item_name' => 'Semen Curah', 'item_group_id' => $this->item->item_group_id,
            'uom_id' => $this->item->uom_id, 'standard_rate' => 1200000, 'qty_category' => QtyCategory::WEIGHT,
        ]);
        $this->seedStock($weightItem->id, $this->warehouse->id, 100, unitCost: 1100000);

        $invoice = $this->invoiceService->create([
            'invoice_type' => InvoiceType::GOODS->value,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'items' => [['item_id' => $weightItem->id, 'qty' => 2.75, 'rate' => 1200000]],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $line = $invoice->items->first();
        $this->assertEquals(2.75, (float) $line->qty);
        $this->assertSame(QtyCategory::WEIGHT, $line->qty_category);

        $before = $this->stockLedgerService->getCurrentBalance($weightItem->id, $this->warehouse->id);
        $this->invoiceService->submit($invoice);
        $after = $this->stockLedgerService->getCurrentBalance($weightItem->id, $this->warehouse->id);
        $this->assertEquals($before - 2.75, $after);
    }

    public function test_direct_goods_invoice_rejects_a_fractional_qty_for_a_unit_category_item(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessageMatches('/dihitung per satuan/');

        $this->invoiceService->create([
            'invoice_type' => InvoiceType::GOODS->value,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'items' => [['item_id' => $this->item->id, 'qty' => 2.5, 'rate' => 60000]],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    /** A Goods invoice never lets the user type a qty — it's copied verbatim from the completed Delivery (InvoiceService::createGoods()), so a weight item's decimal qty must survive that copy without truncating.
        Direct-Delivery *creation* was removed 2026-10-08 — this Delivery is built directly (the
        way an already-existing one would look) since the point of this test is the Invoice-side
        qty copy, not how the Delivery itself came to exist. */
    public function test_goods_invoice_created_from_a_delivery_preserves_a_weight_items_decimal_qty(): void
    {
        $weightItem = Item::query()->create([
            'item_code' => 'ITM-CURAH', 'item_name' => 'Semen Curah', 'item_group_id' => $this->item->item_group_id,
            'uom_id' => $this->item->uom_id, 'standard_rate' => 1200000, 'qty_category' => QtyCategory::WEIGHT,
        ]);
        $this->seedStock($weightItem->id, $this->warehouse->id, 100, unitCost: 1100000);

        $delivery = \App\Models\Delivery::query()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        \App\Models\DeliveryItem::query()->create([
            'delivery_id' => $delivery->id,
            'item_id' => $weightItem->id,
            'item_code' => $weightItem->item_code,
            'item_name' => $weightItem->item_name,
            'uom' => 'Zak',
            'uom_factor' => 1,
            'rate' => 1200000,
            'qty' => 3.25,
            'qty_category' => QtyCategory::WEIGHT->value,
            'amount' => 3.25 * 1200000,
            'net_amount' => 3.25 * 1200000,
            'tax_amount' => 0,
        ]);
        $delivery = $this->deliveryService->complete($delivery->fresh(['items']));

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $line = $invoice->items->first();
        $this->assertEquals(3.25, (float) $line->qty);
        $this->assertSame(QtyCategory::WEIGHT, $line->qty_category);
    }

    /** Editing a Draft Direct Goods invoice's Qty (InvoiceService::applyDraftItemChanges) must accept a weight item's decimal correction, not silently round it to a whole number. */
    public function test_editing_a_draft_direct_goods_invoice_accepts_a_decimal_qty_correction_for_a_weight_item(): void
    {
        $weightItem = Item::query()->create([
            'item_code' => 'ITM-CURAH', 'item_name' => 'Semen Curah', 'item_group_id' => $this->item->item_group_id,
            'uom_id' => $this->item->uom_id, 'standard_rate' => 1200000, 'qty_category' => QtyCategory::WEIGHT,
        ]);
        $this->seedStock($weightItem->id, $this->warehouse->id, 100, unitCost: 1100000);

        $invoice = $this->invoiceService->create([
            'invoice_type' => InvoiceType::GOODS->value,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'items' => [['item_id' => $weightItem->id, 'qty' => 5, 'rate' => 1200000]],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $line = $invoice->items->first();
        $invoice = $this->invoiceService->update($invoice, [
            'items' => [['id' => $line->id, 'qty' => 4.4, 'rate' => 1200000]],
        ]);

        $this->assertEquals(4.4, (float) $invoice->items->first()->qty);
    }
}
