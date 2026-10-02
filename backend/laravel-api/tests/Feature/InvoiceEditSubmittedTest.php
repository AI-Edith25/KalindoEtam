<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Enums\InvoiceType;
use App\Enums\StockVoucherType;
use App\Models\FifoLayer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\ReceiptEntry;
use App\Models\StockLedger;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\PaymentAllocationService;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A Submitted Invoice used to be fully locked (Cancel -> Create New was the only correction
 * path). This relaxation (updateSubmitted()/applySubmittedItemChanges()) allows header fields and
 * Qty/Rate/Tax per existing line to be corrected — no add/remove — even once payments/Credit/
 * Debit Notes exist, reversing and reposting the GL entry and resizing Accounts Receivable by the
 * grand_total delta.
 */
class InvoiceEditSubmittedTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;

    protected DeliveryService $deliveryService;

    protected InvoiceService $invoiceService;

    protected PaymentAllocationService $paymentAllocationService;

    protected Customer $customer;

    protected Warehouse $warehouse;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);
        $this->deliveryService = app(DeliveryService::class);
        $this->invoiceService = app(InvoiceService::class);
        $this->paymentAllocationService = app(PaymentAllocationService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = Item::query()->create(['item_code' => 'ITM-1', 'item_name' => 'Widget', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 10000]);

        $this->seedStock($this->item->id, $this->warehouse->id, 1000);
    }

    protected function accountId(string $code): string
    {
        return ChartOfAccount::query()->where('code', $code)->firstOrFail()->id;
    }

    protected function submittedInvoice(int $qty = 10, float $rate = 10000): Invoice
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate]],
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
        $delivery = $this->deliveryService->complete($delivery);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        return $this->invoiceService->submit($invoice);
    }

    protected function pay(Invoice $invoice, float $amount): void
    {
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();
        $payment = ReceiptEntry::query()->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => $this->accountId('1100'),
            'payment_method' => PaymentMethod::BANK_TRANSFER,
            'total_amount' => $amount,
            'allocated_amount' => 0,
        ])->submit();
        $this->paymentAllocationService->allocateBatch($payment, [
            ['accounts_receivable_id' => $accountsReceivable->id, 'amount' => $amount],
        ]);
    }

    /**
     * Mirrors exactly what SalesInvoiceImportService::createGoodsInvoice() does — created Draft
     * via the repository directly (no Delivery/Sales Order, import_source_type set, affects_stock
     * left at its column default of false), then submit()ted. One real-item line and, optionally,
     * one Miscellaneous/freeform line (item_id null) to mirror a GOODS-type import's own mixed
     * line shape.
     */
    protected function importedInvoice(bool $withMiscLine = false): Invoice
    {
        $invoice = Invoice::query()->create([
            'invoice_type' => InvoiceType::GOODS,
            'customer_id' => $this->customer->id,
            'location_warehouse_id' => $this->warehouse->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'subtotal' => 100000,
            'grand_total' => 100000,
            'tax_amount' => 0,
            'source_document_number' => 'LEGACY-1',
            'import_source_type' => 'historical_invoice',
        ]);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'item_id' => $this->item->id,
            'item_code' => $this->item->item_code,
            'item_name' => $this->item->item_name,
            'uom' => 'Pcs',
            'rate' => 10000,
            'qty' => 10,
            'amount' => 100000,
            'net_amount' => 100000,
        ]);

        if ($withMiscLine) {
            InvoiceItem::query()->create([
                'invoice_id' => $invoice->id,
                'item_id' => null,
                'item_code' => null,
                'item_name' => 'Ongkos Kirim',
                'uom' => null,
                'rate' => 5000,
                'qty' => 1,
                'amount' => 5000,
                'net_amount' => 5000,
            ]);
        }

        return $this->invoiceService->submit($invoice->fresh());
    }

    public function test_imported_invoice_does_not_move_stock_by_default(): void
    {
        $invoice = $this->importedInvoice();

        $this->assertSame(0, StockLedger::query()->where('voucher_type', StockVoucherType::DIRECT_INVOICE)->count());
        $this->assertSame(0, FifoLayer::query()->where('source_type', StockVoucherType::DIRECT_INVOICE)->where('source_id', $invoice->id)->count());
        $this->assertFalse($invoice->affects_stock);
    }

    public function test_turning_on_affects_stock_consumes_fifo_stock_for_real_item_lines_only(): void
    {
        $invoice = $this->importedInvoice(withMiscLine: true);

        $updated = $this->invoiceService->update($invoice, [
            'affects_stock' => true,
            'lock_version' => $invoice->lock_version,
        ]);

        $this->assertTrue($updated->affects_stock);

        $consumption = StockLedger::query()->where('voucher_type', StockVoucherType::DIRECT_INVOICE)->where('item_id', $this->item->id)->sole();
        $this->assertEquals(-10, (float) $consumption->qty_change);

        // The misc line (item_id null) has nothing to consume against — no entry for it, and no error.
        $this->assertSame(1, StockLedger::query()->where('voucher_type', StockVoucherType::DIRECT_INVOICE)->count());

        $realLine = $updated->items->firstWhere('item_id', $this->item->id);
        $this->assertNotNull($realLine->unit_cost, 'FIFO-resolved cost written back onto the line');
    }

    public function test_turning_affects_stock_back_off_reverses_the_consumption(): void
    {
        $invoice = $this->importedInvoice();
        $invoice = $this->invoiceService->update($invoice, ['affects_stock' => true, 'lock_version' => $invoice->lock_version]);

        $updated = $this->invoiceService->update($invoice, ['affects_stock' => false, 'lock_version' => $invoice->lock_version]);

        $this->assertFalse($updated->affects_stock);
        $this->assertSame(0, FifoLayer::query()->where('source_type', StockVoucherType::DIRECT_INVOICE)->where('source_id', $invoice->id)->where('qty_remaining', '>', 0)->count());

        $netQtyChange = StockLedger::query()->where('voucher_type', StockVoucherType::DIRECT_INVOICE)->where('item_id', $this->item->id)->sum('qty_change');
        $this->assertEquals(0, (float) $netQtyChange, 'posted OUT then reversed IN nets to zero');
    }

    public function test_affects_stock_is_rejected_without_a_resolved_location(): void
    {
        $invoice = $this->importedInvoice();
        $invoice = $this->invoiceService->update($invoice, ['location_warehouse_id' => null, 'lock_version' => $invoice->lock_version]);

        try {
            $this->invoiceService->update($invoice, ['affects_stock' => true, 'lock_version' => $invoice->lock_version]);
            $this->fail('Expected enabling affects_stock without a Location to throw.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('Pilih Location', $e->getMessage());
        }
    }

    public function test_affects_stock_is_rejected_for_a_transportation_invoice(): void
    {
        $invoice = Invoice::query()->create([
            'invoice_type' => InvoiceType::TRANSPORTATION,
            'customer_id' => $this->customer->id,
            'location_warehouse_id' => $this->warehouse->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'subtotal' => 50000,
            'grand_total' => 50000,
            'tax_amount' => 0,
            'source_document_number' => 'LEGACY-2',
            'import_source_type' => 'historical_invoice',
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'item_id' => null,
            'item_name' => 'Angkutan',
            'rate' => 50000,
            'qty' => 1,
            'amount' => 50000,
            'net_amount' => 50000,
        ]);
        $invoice = $this->invoiceService->submit($invoice->fresh());

        try {
            $this->invoiceService->update($invoice, ['affects_stock' => true, 'lock_version' => $invoice->lock_version]);
            $this->fail('Expected enabling affects_stock on a Transportation invoice to throw.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('hanya berlaku untuk Invoice bertipe Goods', $e->getMessage());
        }
    }

    public function test_header_fields_are_editable_on_a_submitted_invoice(): void
    {
        $invoice = $this->submittedInvoice();

        $updated = $this->invoiceService->update($invoice, [
            'attention' => 'Pak Budi',
            'tel' => '0812345',
            'customer_address' => 'Jl. Baru No. 1',
            'remarks' => 'Corrected after submit',
            'lock_version' => $invoice->lock_version,
        ]);

        $this->assertSame('submitted', $updated->status->value, 'still submitted — edit never reverts status');
        $this->assertSame('Pak Budi', $updated->attention);
        $this->assertSame('Jl. Baru No. 1', $updated->customer_address);
        $this->assertSame(2, $updated->lock_version);
    }

    public function test_qty_edit_recomputes_totals_and_reverses_and_reposts_the_journal_entry(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 10000);
        $line = $invoice->items->first();
        $ar = $invoice->accountsReceivable;
        $this->assertEquals(100000, (float) $ar->amount);

        $originalJournal = \App\Models\JournalEntry::query()->where('reference_type', $invoice->getMorphClass())->where('reference_id', $invoice->id)->sole();

        $updated = $this->invoiceService->update($invoice, [
            'lock_version' => $invoice->lock_version,
            'items' => [['id' => $line->id, 'qty' => 15]],
        ]);

        $this->assertEquals(150000, (float) $updated->subtotal);
        $this->assertEquals(150000, (float) $updated->grand_total);

        $originalJournal->refresh();
        $this->assertNotNull($originalJournal->reversed_by_id, 'the original journal entry was reversed');

        $newJournal = \App\Models\JournalEntry::query()->where('reference_type', $invoice->getMorphClass())->where('reference_id', $invoice->id)->whereNull('reversed_by_id')->whereNull('reverses_id')->where('id', '!=', $originalJournal->id)->sole();
        $this->assertNotSame($originalJournal->id, $newJournal->id);

        $ar->refresh();
        $this->assertEquals(150000, (float) $ar->amount, 'AR resized by the delta on top of its current amount');
        $this->assertEquals(0, (float) $ar->paid_amount);
    }

    public function test_qty_edit_is_allowed_and_recomputes_outstanding_even_after_a_partial_payment(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 10000);
        $this->pay($invoice, 40000);

        $ar = $invoice->accountsReceivable->fresh();
        $this->assertEquals(40000, (float) $ar->paid_amount);
        $this->assertSame('partially_paid', $ar->status->value);

        $line = $invoice->items->first();
        $updated = $this->invoiceService->update($invoice, [
            'lock_version' => $invoice->lock_version,
            'items' => [['id' => $line->id, 'qty' => 5]],
        ]);

        $this->assertEquals(50000, (float) $updated->grand_total);

        $ar->refresh();
        $this->assertEquals(50000, (float) $ar->amount);
        $this->assertEquals(40000, (float) $ar->paid_amount, 'payment untouched by the edit');
        $this->assertSame('partially_paid', $ar->status->value, 'still partial: 40k paid of 50k');
    }

    public function test_qty_edit_can_flip_status_to_paid_when_the_new_total_matches_what_was_already_paid(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 10000);
        $this->pay($invoice, 50000);

        $line = $invoice->items->first();
        $this->invoiceService->update($invoice, [
            'lock_version' => $invoice->lock_version,
            'items' => [['id' => $line->id, 'qty' => 5]],
        ]);

        $ar = $invoice->accountsReceivable->fresh();
        $this->assertEquals(50000, (float) $ar->amount);
        $this->assertEquals(50000, (float) $ar->paid_amount);
        $this->assertSame('paid', $ar->status->value);
    }

    public function test_item_rows_cannot_be_added_or_removed(): void
    {
        $invoice = $this->submittedInvoice();

        try {
            $this->invoiceService->update($invoice, ['items' => []]);
            $this->fail('Expected removing every line to throw.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('tidak bisa ditambah atau dihapus', $e->getMessage());
        }
    }

    public function test_cancelled_invoice_cannot_be_updated(): void
    {
        $invoice = $this->submittedInvoice();
        $invoice = $this->invoiceService->cancel($invoice);

        try {
            $this->invoiceService->update($invoice, ['remarks' => 'Should not apply']);
            $this->fail('Expected updating a cancelled Invoice to throw.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('Only draft Invoices can be updated', $e->getMessage());
        }
    }

    public function test_stale_lock_version_is_rejected_with_409(): void
    {
        $invoice = $this->submittedInvoice();

        $this->invoiceService->update($invoice, ['remarks' => 'First edit', 'lock_version' => $invoice->lock_version]);

        try {
            $this->invoiceService->update($invoice, ['remarks' => 'Second, stale edit', 'lock_version' => $invoice->lock_version]);
            $this->fail('Expected a stale lock_version to throw.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('sudah diubah oleh pengguna lain', $e->getMessage());
        }
    }

    public function test_audit_log_records_the_edit_with_field_level_changes(): void
    {
        $invoice = $this->submittedInvoice();

        $this->invoiceService->update($invoice, ['attention' => 'Pak Rudi', 'lock_version' => $invoice->lock_version]);

        $log = AuditLog::query()->where('module', 'invoice')->where('action', 'updated')->latest('created_at')->firstOrFail();
        $this->assertSame($invoice->id, $log->properties['subject_id']);
        $this->assertSame('Pak Rudi', $log->properties['changes']['attention']['new']);
    }
}
