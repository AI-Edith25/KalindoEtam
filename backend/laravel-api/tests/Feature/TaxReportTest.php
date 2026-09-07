<?php

namespace Tests\Feature;

use App\Enums\TaxCalculationMode;
use App\Enums\TaxTransactionType;
use App\Enums\TaxType;
use App\Enums\WarehouseType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Repositories\TaxReportRepository;
use App\Services\CreditNoteService;
use App\Services\DeliveryService;
use App\Services\GoodsReceiptService;
use App\Services\InvoiceService;
use App\Services\PurchaseInvoiceService;
use App\Services\PurchaseOrderService;
use App\Services\PurchaseReturnService;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** PPN Keluaran / PPN Masukan — built with real services (SalesOrder->Delivery->Invoice, PurchaseOrder->GoodsReceipt->PurchaseInvoice), no seeder. */
class TaxReportTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
    protected CreditNoteService $creditNoteService;
    protected PurchaseOrderService $purchaseOrderService;
    protected GoodsReceiptService $goodsReceiptService;
    protected PurchaseInvoiceService $purchaseInvoiceService;
    protected PurchaseReturnService $purchaseReturnService;
    protected TaxReportRepository $taxReportRepository;
    protected Customer $customer;
    protected Supplier $supplier;
    protected Warehouse $warehouse;
    protected Item $item;
    protected Tax $ppn11;
    protected Tax $bebas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);
        $this->deliveryService = app(DeliveryService::class);
        $this->invoiceService = app(InvoiceService::class);
        $this->creditNoteService = app(CreditNoteService::class);
        $this->purchaseOrderService = app(PurchaseOrderService::class);
        $this->goodsReceiptService = app(GoodsReceiptService::class);
        $this->purchaseInvoiceService = app(PurchaseInvoiceService::class);
        $this->purchaseReturnService = app(PurchaseReturnService::class);
        $this->taxReportRepository = app(TaxReportRepository::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $this->supplier = Supplier::query()->create(['supplier_code' => 'S001', 'supplier_name' => 'Acme Supplier']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM-1', 'item_name' => 'Widget', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 10000,
        ]);
        $this->seedStock($this->item->id, $this->warehouse->id, 1000);

        $this->ppn11 = Tax::query()->create([
            'code' => 'PPN11-S', 'name' => 'PPN 11% Sales', 'type' => TaxType::VAT, 'transaction_type' => TaxTransactionType::SALES,
            'rate' => 11.00, 'calculation_mode' => TaxCalculationMode::EXCLUSIVE, 'is_active' => true,
        ]);
        $this->bebas = Tax::query()->create([
            'code' => 'BEBAS-S', 'name' => 'PPN Dibebaskan', 'type' => TaxType::EXEMPT, 'transaction_type' => TaxTransactionType::SALES,
            'rate' => 0.00, 'calculation_mode' => TaxCalculationMode::EXCLUSIVE, 'is_active' => true,
        ]);
    }

    protected function submittedInvoice(?Tax $tax, string $invoiceDate, int $qty = 5, float $rate = 20000): Invoice
    {
        $this->actingAsCreditOverride();

        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => $invoiceDate,
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate, 'tax_id' => $tax?->id]],
            'override_credit_block' => true,
            'override_reason' => 'Test fixture: intentional back-dated invoice for Tax report coverage.',
        ]);
        $this->approveDocument($salesOrder);

        $this->actingAsCreditOverride();
        $this->salesOrderService->approve($salesOrder, true, 'Test fixture: intentional back-dated invoice for Tax report coverage.');

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => $invoiceDate,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => $qty]],
        ]);
        $this->deliveryService->complete($delivery);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => $invoiceDate,
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        return $this->invoiceService->submit($invoice);
    }

    protected function submittedPurchaseInvoice(string $invoiceDate, int $qty = 5, float $rate = 20000, float $taxAmount = 0): \App\Models\PurchaseInvoice
    {
        $purchaseOrder = $this->purchaseOrderService->create([
            'supplier_id' => $this->supplier->id,
            'order_date' => $invoiceDate,
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate]],
        ]);
        $this->approveDocument($purchaseOrder);
        $purchaseOrder = $this->purchaseOrderService->submit($purchaseOrder);

        $goodsReceipt = $this->goodsReceiptService->create([
            'purchase_order_id' => $purchaseOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => $invoiceDate,
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['purchase_order_item_id' => $purchaseOrder->items->first()->id, 'qty' => $qty]],
        ]);
        $goodsReceipt = $this->goodsReceiptService->submit($goodsReceipt);

        $purchaseInvoice = $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$goodsReceipt->id],
            'invoice_date' => $invoiceDate,
            'due_date' => now()->addDays(30)->toDateString(),
            'tax_amount' => $taxAmount,
        ]);

        return $this->purchaseInvoiceService->submit($purchaseInvoice);
    }

    /** Requirement #1: PPN 11% Exclusive invoice -> DPP + PPN = Total. */
    public function test_ppn_11_percent_invoice_dpp_plus_ppn_equals_total(): void
    {
        $this->submittedInvoice($this->ppn11, '2026-03-10', qty: 5, rate: 20000); // 100000 DPP, 11000 PPN

        $rows = $this->taxReportRepository->allOutputTax(['date_from' => '2026-03-01', 'date_to' => '2026-03-31']);

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertEquals('PPN11-S', $row->tax_code);
        $this->assertEquals(11.00, (float) $row->tax_rate);
        $this->assertEquals(100000.0, (float) $row->dpp);
        $this->assertEquals(11000.0, (float) $row->ppn);
        $this->assertEquals(111000.0, (float) $row->dpp + (float) $row->ppn);
    }

    /** Requirement #2: BEBAS/PPN0-coded invoice still appears, PPN = 0, DPP populated. */
    public function test_zero_rated_invoice_still_appears_with_zero_ppn(): void
    {
        $this->submittedInvoice($this->bebas, '2026-03-11', qty: 3, rate: 50000); // 150000 DPP, 0 PPN

        $rows = $this->taxReportRepository->allOutputTax(['date_from' => '2026-03-01', 'date_to' => '2026-03-31']);

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertEquals('BEBAS-S', $row->tax_code);
        $this->assertEquals(150000.0, (float) $row->dpp);
        $this->assertEquals(0.0, (float) $row->ppn);
    }

    /** Requirement #4: a Draft invoice never appears. */
    public function test_draft_invoice_never_appears(): void
    {
        $this->actingAsCreditOverride();
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => '2026-03-12',
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 10000, 'tax_id' => $this->ppn11->id]],
            'override_credit_block' => true,
            'override_reason' => 'Test fixture.',
        ]);
        $this->approveDocument($salesOrder);
        $this->actingAsCreditOverride();
        $this->salesOrderService->approve($salesOrder, true, 'Test fixture.');

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => '2026-03-12',
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => 1]],
        ]);
        $this->deliveryService->complete($delivery);

        // Created but deliberately never submit()'d.
        $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => '2026-03-12',
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $rows = $this->taxReportRepository->allOutputTax(['date_from' => '2026-03-01', 'date_to' => '2026-03-31']);
        $this->assertCount(0, $rows);
    }

    /** Requirement #3: a Credit Note reduces DPP/PPN in the Credit Note's own period, not the original invoice's. */
    public function test_credit_note_reduces_dpp_and_ppn_in_its_own_period(): void
    {
        $invoice = $this->submittedInvoice($this->ppn11, '2026-02-15', qty: 5, rate: 20000); // 100000 DPP, 11000 PPN
        $invoiceItem = $invoice->items->first();

        $this->actingAsCreditOverride();
        $creditNote = $this->creditNoteService->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => '2026-03-05',
            'reason' => \App\Enums\CreditNoteReason::RETURNED_GOODS->value,
            'tax_amount' => 2200, // 11% of the 20000 amount being credited
            'items' => [['invoice_item_id' => $invoiceItem->id, 'qty_credited' => 1, 'amount' => 20000]],
        ]);
        $this->creditNoteService->submit($creditNote);

        $februaryRows = $this->taxReportRepository->allOutputTax(['date_from' => '2026-02-01', 'date_to' => '2026-02-28']);
        $marchRows = $this->taxReportRepository->allOutputTax(['date_from' => '2026-03-01', 'date_to' => '2026-03-31']);

        // February still shows the original invoice's full, unreduced totals — the reduction
        // belongs to March (the Credit Note's own date), not retroactively applied to February.
        $this->assertCount(1, $februaryRows);
        $this->assertEquals(100000.0, (float) $februaryRows->first()->dpp);
        $this->assertEquals(11000.0, (float) $februaryRows->first()->ppn);

        $this->assertCount(1, $marchRows);
        $creditRow = $marchRows->first();
        $this->assertEquals('credit_note', $creditRow->document_type);
        $this->assertEquals(-20000.0, (float) $creditRow->dpp);
        $this->assertEquals(-2200.0, (float) $creditRow->ppn);
        $this->assertEquals('PPN11-S', $creditRow->tax_code); // resolved from the parent invoice's item tax
    }

    /** Requirement #5: Total PPN Keluaran - Total PPN Masukan = Selisih. */
    public function test_output_minus_input_equals_selisih(): void
    {
        $this->submittedInvoice($this->ppn11, '2026-04-05', qty: 5, rate: 20000); // 100000 DPP, 11000 PPN out
        $this->submittedPurchaseInvoice('2026-04-06', qty: 5, rate: 10000, taxAmount: 5500); // 50000 DPP, 5500 PPN in

        $filters = ['date_from' => '2026-04-01', 'date_to' => '2026-04-30'];
        $outputTotals = $this->taxReportRepository->outputTaxTotals($filters);
        $inputTotals = $this->taxReportRepository->inputTaxTotals($filters);

        $this->assertEquals(11000.0, $outputTotals['ppn']);
        $this->assertEquals(5500.0, $inputTotals['ppn']);

        $selisih = $outputTotals['ppn'] - $inputTotals['ppn'];
        $this->assertEquals(5500.0, $selisih);
    }

    /** Purchase Invoice has no tax-code trail anywhere — Kode Pajak/Tarif are always null, by design. */
    public function test_purchase_invoice_row_has_no_tax_code(): void
    {
        $this->submittedPurchaseInvoice('2026-04-10', qty: 2, rate: 15000, taxAmount: 3300);

        $rows = $this->taxReportRepository->allInputTax(['date_from' => '2026-04-01', 'date_to' => '2026-04-30']);

        $this->assertCount(1, $rows);
        $this->assertNull($rows->first()->tax_id);
        $this->assertNull($rows->first()->tax_code);
        $this->assertEquals(30000.0, (float) $rows->first()->dpp);
        $this->assertEquals(3300.0, (float) $rows->first()->ppn);
    }

    /** Purchase Return reduces PPN Masukan the same way Credit Note reduces PPN Keluaran. */
    public function test_purchase_return_reduces_input_dpp_and_ppn(): void
    {
        $purchaseInvoice = $this->submittedPurchaseInvoice('2026-04-12', qty: 5, rate: 20000, taxAmount: 11000);
        $piItem = $purchaseInvoice->items->first();

        $purchaseReturn = app(PurchaseReturnService::class)->create([
            'purchase_invoice_id' => $purchaseInvoice->id,
            'return_date' => '2026-04-20',
            'reason' => \App\Enums\PurchaseReturnReason::QUANTITY_DISCREPANCY->value,
            'tax_amount' => 2200,
            'items' => [['purchase_invoice_item_id' => $piItem->id, 'qty_returned' => 1, 'amount' => 20000]],
        ]);
        app(PurchaseReturnService::class)->submit($purchaseReturn);

        $rows = $this->taxReportRepository->allInputTax(['date_from' => '2026-04-01', 'date_to' => '2026-04-30']);
        $returnRow = $rows->firstWhere('document_type', 'purchase_return');

        $this->assertNotNull($returnRow);
        $this->assertEquals(-20000.0, (float) $returnRow->dpp);
        $this->assertEquals(-2200.0, (float) $returnRow->ppn);
    }
}
