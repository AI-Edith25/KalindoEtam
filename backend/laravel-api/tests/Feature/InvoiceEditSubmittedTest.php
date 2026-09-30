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
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\ReceiptEntry;
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
