<?php

namespace Tests\Feature;

use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\TaxCalculationMode;
use App\Enums\TaxTransactionType;
use App\Enums\TaxType;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Http\Requests\StoreInvoiceRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\PurchaseOrderService;
use App\Services\SalesOrderService;
use App\Services\StockLedgerService;
use App\Services\TaxService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaxEngineTest extends TestCase
{
    use RefreshDatabase;

    protected TaxService $taxService;
    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
    protected PurchaseOrderService $purchaseOrderService;
    protected Customer $customer;
    protected Supplier $supplier;
    protected Warehouse $warehouse;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->taxService = app(TaxService::class);
        $this->salesOrderService = app(SalesOrderService::class);
        $this->deliveryService = app(DeliveryService::class);
        $this->invoiceService = app(InvoiceService::class);
        $this->purchaseOrderService = app(PurchaseOrderService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $branch = Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $this->supplier = Supplier::query()->create(['supplier_code' => 'S001', 'supplier_name' => 'Acme Supplies']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM-1',
            'item_name' => 'Widget',
            'item_group_id' => $itemGroup->id,
            'uom_id' => $uom->id,
            'standard_rate' => 10000,
        ]);

        app(StockLedgerService::class)->record(
            itemId: $this->item->id,
            warehouseId: $this->warehouse->id,
            transactionType: StockTransactionType::IN,
            voucherType: StockVoucherType::STOCK_IN,
            voucherId: (string) Str::uuid(),
            qtyChange: 1000,
            postingDatetime: now(),
        );
    }

    protected function makeTax(array $overrides = []): Tax
    {
        return Tax::query()->create(array_merge([
            'code' => 'PPN11',
            'name' => 'PPN 11%',
            'type' => TaxType::VAT,
            'transaction_type' => TaxTransactionType::SALES,
            'rate' => 11,
            'calculation_mode' => TaxCalculationMode::EXCLUSIVE,
            'is_active' => true,
        ], $overrides));
    }

    protected function submittedDelivery(int $qty = 10, float $rate = 10000, ?string $taxId = null): \App\Models\Delivery
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate, 'tax_id' => $taxId]],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->approve($salesOrder);

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => $qty]],
        ]);

        return $this->deliveryService->complete($delivery);
    }

    // --- TaxService::calculate() — the single source of truth ---

    public function test_calculate_exclusive_vat_adds_tax_on_top_of_the_base_amount(): void
    {
        $tax = $this->makeTax(['calculation_mode' => TaxCalculationMode::EXCLUSIVE]);

        $result = $this->taxService->calculate(100000, $tax);

        $this->assertEquals(11000.0, $result['tax_amount']);
        $this->assertEquals(100000.0, $result['base_amount']);
        $this->assertEquals(111000.0, $result['total']);
    }

    public function test_calculate_inclusive_vat_backs_the_tax_out_of_the_base_amount(): void
    {
        $tax = $this->makeTax(['calculation_mode' => TaxCalculationMode::INCLUSIVE]);

        $result = $this->taxService->calculate(111000, $tax);

        $this->assertEquals(11000.0, $result['tax_amount']);
        $this->assertEquals(100000.0, $result['base_amount']);
        $this->assertEquals(111000.0, $result['total']); // unchanged — tax was already inside it
    }

    public function test_calculate_zero_rated_always_yields_zero_regardless_of_stored_rate(): void
    {
        $tax = $this->makeTax(['code' => 'PPN0', 'type' => TaxType::ZERO_RATED, 'rate' => 11]);

        $result = $this->taxService->calculate(100000, $tax);

        $this->assertEquals(0.0, $result['tax_amount']);
        $this->assertEquals(100000.0, $result['total']);
    }

    public function test_calculate_exempt_yields_zero(): void
    {
        $tax = $this->makeTax(['code' => 'EXEMPT', 'type' => TaxType::EXEMPT, 'rate' => 0]);

        $result = $this->taxService->calculate(100000, $tax);

        $this->assertEquals(0.0, $result['tax_amount']);
    }

    public function test_calculate_with_no_tax_selected_yields_zero(): void
    {
        $result = $this->taxService->calculate(100000, null);

        $this->assertEquals(0.0, $result['tax_amount']);
        $this->assertEquals(100000.0, $result['total']);
    }

    // --- Tax Status: prefer deactivation, guard deletion ---

    public function test_delete_is_blocked_when_the_tax_is_referenced_by_an_invoice(): void
    {
        $tax = $this->makeTax();
        // Goods invoices inherit tax_id from their Sales Order (B1 of the workflow spec) —
        // it's never passed to InvoiceService::create() directly.
        $delivery = $this->submittedDelivery(qty: 5, rate: 20000, taxId: $tax->id);
        $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->expectException(BusinessException::class);
        $this->taxService->delete($tax);
    }

    public function test_delete_is_blocked_when_the_tax_is_referenced_by_a_purchase_order(): void
    {
        $tax = $this->makeTax();
        $this->purchaseOrderService->create([
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => 10000]],
            'tax_id' => $tax->id,
        ]);

        $this->expectException(BusinessException::class);
        $this->taxService->delete($tax);
    }

    public function test_delete_succeeds_when_the_tax_is_not_referenced_by_any_document(): void
    {
        $tax = $this->makeTax();

        $this->taxService->delete($tax);

        $this->assertSoftDeleted('taxes', ['id' => $tax->id]);
    }

    public function test_an_inactive_tax_cannot_be_selected_for_a_new_document(): void
    {
        $tax = $this->makeTax(['is_active' => false]);

        $validator = validator(['delivery_ids' => [(string) Str::uuid()], 'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'tax_id' => $tax->id], (new StoreInvoiceRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('tax_id', $validator->errors()->toArray());
    }

    // --- Invoice Integration ---

    public function test_invoice_create_inherits_the_tax_amount_from_the_sales_order(): void
    {
        $tax = $this->makeTax();
        // Tax is per-line now — attached to the Sales Order's line, flows through the
        // Delivery line to the Invoice line unchanged, and sums into the header total.
        // Goods invoices have no single header tax anymore (tax_id stays null).
        $delivery = $this->submittedDelivery(qty: 5, rate: 20000, taxId: $tax->id); // subtotal 100000

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertNull($invoice->tax_id);
        $this->assertEquals($tax->id, $invoice->items->first()->tax_id);
        $this->assertEquals(11000.0, (float) $invoice->items->first()->tax_amount);
        $this->assertEquals(11000.0, (float) $invoice->tax_amount);
        $this->assertEquals(111000.0, (float) $invoice->grand_total);
    }

    public function test_invoice_create_ignores_a_client_supplied_tax_when_the_sales_order_has_none(): void
    {
        // Even an explicit tax_id/tax_amount in the request is ignored for Goods invoices —
        // the Sales Order (which has no tax here) is the only source (B1/B3).
        $tax = $this->makeTax();
        $delivery = $this->submittedDelivery(qty: 5, rate: 20000);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'tax_id' => $tax->id,
            'tax_amount' => 7500,
        ]);

        $this->assertNull($invoice->tax_id);
        $this->assertEquals(0.0, (float) $invoice->tax_amount);
    }

    public function test_invoice_update_never_changes_the_inherited_tax(): void
    {
        $taxA = $this->makeTax(['code' => 'PPN11', 'rate' => 11]);
        $taxB = $this->makeTax(['code' => 'PPN12', 'rate' => 12]);
        $delivery = $this->submittedDelivery(qty: 5, rate: 20000, taxId: $taxA->id); // subtotal 100000

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $this->assertEquals(11000.0, (float) $invoice->tax_amount);

        // Goods invoice items are never editable after creation (see update()'s own
        // docblock) — an explicit tax_id in the update payload is ignored, the Invoice
        // keeps whatever its lines resolved to at creation.
        $updated = $this->invoiceService->update($invoice, ['tax_id' => $taxB->id]);

        $this->assertNull($updated->tax_id);
        $this->assertEquals(11000.0, (float) $updated->tax_amount);
        $this->assertEquals(111000.0, (float) $updated->grand_total);
    }

    public function test_journal_lines_route_the_calculated_tax_amount_unchanged(): void
    {
        // Proves Journal Integration is untouched — journalLines() still just reads tax_amount,
        // whatever computed it. See docs/TAX_ENGINE_DESIGN.md §7.
        $tax = $this->makeTax();
        $delivery = $this->submittedDelivery(qty: 5, rate: 20000, taxId: $tax->id);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $taxLine = collect($invoice->journalLines())->firstWhere('account', '2100');

        $this->assertNotNull($taxLine);
        $this->assertEquals(11000.0, $taxLine['amount']);
        $this->assertEquals('credit', $taxLine['type']);
    }

    // --- Purchase Integration ---

    public function test_purchase_order_create_calculates_tax_amount_from_the_selected_tax(): void
    {
        // Tax is per-line now (PurchaseOrderService::resolveLineTax()) — the header tax_id
        // sent alongside is stored verbatim as a display-only marker, not what drives calculation.
        $tax = $this->makeTax(['transaction_type' => TaxTransactionType::PURCHASE]);

        $purchaseOrder = $this->purchaseOrderService->create([
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => 20000, 'tax_id' => $tax->id]], // subtotal 100000
        ]);

        $this->assertEquals($tax->id, $purchaseOrder->items->first()->tax_id);
        $this->assertEquals(100000.0, (float) $purchaseOrder->total_amount);
        $this->assertEquals(11000.0, (float) $purchaseOrder->tax_amount);
        $this->assertEquals(111000.0, (float) $purchaseOrder->grand_total);
    }

    public function test_purchase_order_create_falls_back_to_a_raw_tax_amount_when_no_tax_is_selected(): void
    {
        $purchaseOrder = $this->purchaseOrderService->create([
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => 20000]],
        ]);

        $this->assertNull($purchaseOrder->tax_id);
        $this->assertEquals(0.0, (float) $purchaseOrder->tax_amount);
        $this->assertEquals(100000.0, (float) $purchaseOrder->grand_total);
    }

    // --- Item-driven Purchase/Sales Tax split ---

    public function test_sales_order_and_purchase_order_lines_default_tax_from_the_items_own_purchase_and_sales_tax(): void
    {
        $salesTax = $this->makeTax(['code' => 'SALES11', 'transaction_type' => TaxTransactionType::SALES, 'rate' => 11]);
        $purchaseTax = $this->makeTax(['code' => 'PURCH5', 'transaction_type' => TaxTransactionType::PURCHASE, 'rate' => 5]);
        $item = Item::query()->create([
            'item_code' => 'ITM-TAXED',
            'item_name' => 'Taxed Widget',
            'item_group_id' => $this->item->item_group_id,
            'uom_id' => $this->item->uom_id,
            'standard_rate' => 10000,
            'sales_tax_id' => $salesTax->id,
            'purchase_tax_id' => $purchaseTax->id,
        ]);

        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $item->id, 'qty' => 5, 'rate' => 20000]], // no tax_id sent — defaults from Item
        ]);

        $this->assertEquals($salesTax->id, $salesOrder->items->first()->tax_id);
        $this->assertEquals(11000.0, (float) $salesOrder->items->first()->tax_amount);
        $this->assertEquals(11000.0, (float) $salesOrder->tax_amount);

        $purchaseOrder = $this->purchaseOrderService->create([
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $item->id, 'qty' => 5, 'rate' => 20000]], // no tax_id sent — defaults from Item
        ]);

        $this->assertEquals($purchaseTax->id, $purchaseOrder->items->first()->tax_id);
        $this->assertEquals(5000.0, (float) $purchaseOrder->items->first()->tax_amount);
        $this->assertEquals(5000.0, (float) $purchaseOrder->tax_amount);
    }

    public function test_sales_order_with_mixed_tax_lines_sums_correctly(): void
    {
        $vat = $this->makeTax(['code' => 'PPN11B', 'rate' => 11]);
        $exempt = $this->makeTax(['code' => 'BEBASB', 'type' => TaxType::EXEMPT, 'rate' => 0]);

        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['item_id' => $this->item->id, 'qty' => 5, 'rate' => 20000, 'tax_id' => $vat->id], // 100000, tax 11000
                ['item_id' => $this->item->id, 'qty' => 2, 'rate' => 10000, 'tax_id' => $exempt->id], // 20000, tax 0
            ],
        ]);

        $this->assertEquals(120000.0, (float) $salesOrder->total_amount);
        $this->assertEquals(11000.0, (float) $salesOrder->tax_amount);
        $this->assertEquals(131000.0, (float) $salesOrder->grand_total);
    }

    public function test_line_with_no_tax_on_the_item_works_without_error(): void
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            // $this->item has no purchase_tax_id/sales_tax_id set — legacy Item, no tax_id sent either.
            'items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => 20000]],
        ]);

        $this->assertNull($salesOrder->items->first()->tax_id);
        $this->assertEquals(0.0, (float) $salesOrder->items->first()->tax_amount);
        $this->assertEquals(0.0, (float) $salesOrder->tax_amount);
    }

    public function test_item_purchase_tax_field_rejects_a_sales_only_tax(): void
    {
        $salesTax = $this->makeTax(['transaction_type' => TaxTransactionType::SALES]);

        $validator = validator(
            [
                'item_code' => 'X1',
                'item_name' => 'X',
                'item_group_id' => $this->item->item_group_id,
                'uom_id' => $this->item->uom_id,
                'purchase_tax_id' => $salesTax->id,
            ],
            (new \App\Http\Requests\StoreItemRequest())->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('purchase_tax_id', $validator->errors()->toArray());
    }

    public function test_inclusive_tax_line_backs_the_tax_out_of_the_line_amount(): void
    {
        $inclusiveTax = $this->makeTax(['calculation_mode' => TaxCalculationMode::INCLUSIVE, 'rate' => 11]);

        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 111000, 'tax_id' => $inclusiveTax->id]],
        ]);

        $this->assertEquals(11000.0, (float) $salesOrder->items->first()->tax_amount);
    }
}
