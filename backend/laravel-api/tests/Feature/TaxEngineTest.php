<?php

namespace Tests\Feature;

use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\TaxCalculationMode;
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
            'rate' => 11,
            'is_active' => true,
        ], $overrides));
    }

    protected function submittedDelivery(int $qty = 10, float $rate = 10000): \App\Models\Delivery
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate]],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->submit($salesOrder);

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => $qty]],
        ]);

        return $this->deliveryService->submit($delivery);
    }

    // --- TaxService::calculate() — the single source of truth ---

    public function test_calculate_exclusive_vat_adds_tax_on_top_of_the_base_amount(): void
    {
        $tax = $this->makeTax();

        $result = $this->taxService->calculate(100000, $tax, TaxCalculationMode::EXCLUSIVE);

        $this->assertEquals(11000.0, $result['tax_amount']);
        $this->assertEquals(100000.0, $result['base_amount']);
        $this->assertEquals(111000.0, $result['total']);
    }

    public function test_calculate_inclusive_vat_backs_the_tax_out_of_the_base_amount(): void
    {
        $tax = $this->makeTax();

        $result = $this->taxService->calculate(111000, $tax, TaxCalculationMode::INCLUSIVE);

        $this->assertEquals(11000.0, $result['tax_amount']);
        $this->assertEquals(100000.0, $result['base_amount']);
        $this->assertEquals(111000.0, $result['total']); // unchanged — tax was already inside it
    }

    public function test_calculate_zero_rated_always_yields_zero_regardless_of_stored_rate(): void
    {
        $tax = $this->makeTax(['code' => 'PPN0', 'type' => TaxType::ZERO_RATED, 'rate' => 11]);

        $result = $this->taxService->calculate(100000, $tax, TaxCalculationMode::EXCLUSIVE);

        $this->assertEquals(0.0, $result['tax_amount']);
        $this->assertEquals(100000.0, $result['total']);
    }

    public function test_calculate_exempt_yields_zero(): void
    {
        $tax = $this->makeTax(['code' => 'EXEMPT', 'type' => TaxType::EXEMPT, 'rate' => 0]);

        $result = $this->taxService->calculate(100000, $tax, TaxCalculationMode::EXCLUSIVE);

        $this->assertEquals(0.0, $result['tax_amount']);
    }

    public function test_calculate_with_no_tax_selected_yields_zero(): void
    {
        $result = $this->taxService->calculate(100000, null, TaxCalculationMode::EXCLUSIVE);

        $this->assertEquals(0.0, $result['tax_amount']);
        $this->assertEquals(100000.0, $result['total']);
    }

    // --- Tax Status: prefer deactivation, guard deletion ---

    public function test_delete_is_blocked_when_the_tax_is_referenced_by_an_invoice(): void
    {
        $tax = $this->makeTax();
        $delivery = $this->submittedDelivery(qty: 5, rate: 20000);
        $this->invoiceService->create([
            'delivery_id' => $delivery->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'tax_id' => $tax->id,
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

        $validator = validator(['delivery_id' => (string) Str::uuid(), 'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'tax_id' => $tax->id], (new StoreInvoiceRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('tax_id', $validator->errors()->toArray());
    }

    // --- Invoice Integration ---

    public function test_invoice_create_calculates_tax_amount_from_the_selected_tax(): void
    {
        $tax = $this->makeTax();
        $delivery = $this->submittedDelivery(qty: 5, rate: 20000); // subtotal 100000

        $invoice = $this->invoiceService->create([
            'delivery_id' => $delivery->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'tax_id' => $tax->id,
        ]);

        $this->assertEquals($tax->id, $invoice->tax_id);
        $this->assertEquals(11000.0, (float) $invoice->tax_amount);
        $this->assertEquals(111000.0, (float) $invoice->grand_total);
    }

    public function test_invoice_create_falls_back_to_a_raw_tax_amount_when_no_tax_is_selected(): void
    {
        $delivery = $this->submittedDelivery(qty: 5, rate: 20000);

        $invoice = $this->invoiceService->create([
            'delivery_id' => $delivery->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'tax_amount' => 7500,
        ]);

        $this->assertNull($invoice->tax_id);
        $this->assertEquals(7500.0, (float) $invoice->tax_amount);
    }

    public function test_invoice_update_recalculates_tax_amount_when_a_different_tax_is_selected(): void
    {
        $taxA = $this->makeTax(['code' => 'PPN11', 'rate' => 11]);
        $taxB = $this->makeTax(['code' => 'PPN12', 'rate' => 12]);
        $delivery = $this->submittedDelivery(qty: 5, rate: 20000); // subtotal 100000

        $invoice = $this->invoiceService->create([
            'delivery_id' => $delivery->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'tax_id' => $taxA->id,
        ]);
        $this->assertEquals(11000.0, (float) $invoice->tax_amount);

        $updated = $this->invoiceService->update($invoice, ['tax_id' => $taxB->id]);

        $this->assertEquals($taxB->id, $updated->tax_id);
        $this->assertEquals(12000.0, (float) $updated->tax_amount);
        $this->assertEquals(112000.0, (float) $updated->grand_total);
    }

    public function test_journal_lines_route_the_calculated_tax_amount_unchanged(): void
    {
        // Proves Journal Integration is untouched — journalLines() still just reads tax_amount,
        // whatever computed it. See docs/TAX_ENGINE_DESIGN.md §7.
        $tax = $this->makeTax();
        $delivery = $this->submittedDelivery(qty: 5, rate: 20000);

        $invoice = $this->invoiceService->create([
            'delivery_id' => $delivery->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'tax_id' => $tax->id,
        ]);

        $taxLine = collect($invoice->journalLines())->firstWhere('account', '2100');

        $this->assertNotNull($taxLine);
        $this->assertEquals(11000.0, $taxLine['amount']);
        $this->assertEquals('credit', $taxLine['type']);
    }

    // --- Purchase Integration ---

    public function test_purchase_order_create_calculates_tax_amount_from_the_selected_tax(): void
    {
        $tax = $this->makeTax();

        $purchaseOrder = $this->purchaseOrderService->create([
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => 20000]], // subtotal 100000
            'tax_id' => $tax->id,
        ]);

        $this->assertEquals($tax->id, $purchaseOrder->tax_id);
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
}
