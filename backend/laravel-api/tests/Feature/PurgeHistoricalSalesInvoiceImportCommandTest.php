<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Models\AccountsReceivable;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentAllocation;
use App\Models\ReceiptEntry;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Exercises the SI historical-import rollback — see PurgeHistoricalSalesInvoiceImportCommand's own
 * docblock. Invoices are built directly via Eloquent (the importer that used to create them was
 * removed 2026-10-08, superseded by CustomerOutstandingArchiveImportService) — this command only
 * cares about import_source_type, not how a row came to carry it.
 */
class PurgeHistoricalSalesInvoiceImportCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DocumentEngineSeeder::class);
        $this->customer = Customer::query()->create(['customer_code' => 'CUST1', 'customer_name' => 'Test Customer']);
    }

    private function makeHistoricalInvoice(string $documentNumber): Invoice
    {
        $invoice = Invoice::query()->create([
            'customer_id' => $this->customer->id,
            'document_number' => $documentNumber,
            'invoice_type' => 'goods',
            'invoice_date' => '2026-09-30',
            'due_date' => '2026-09-30',
            'subtotal' => 111000, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => 111000,
            'source_document_number' => $documentNumber,
            'import_source_type' => 'historical_invoice',
        ]);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id, 'item_name' => 'Test Item', 'uom' => 'ZAK',
            'rate' => 10000, 'qty' => 10, 'qty_category' => 'unit', 'amount' => 111000,
        ]);

        AccountsReceivable::query()->create([
            'customer_id' => $this->customer->id, 'invoice_id' => $invoice->id,
            'reference_number' => $documentNumber, 'amount' => 111000, 'paid_amount' => 0,
            'due_date' => '2026-10-30', 'status' => 'unpaid',
        ]);

        $path = 'imports/test-'.uniqid().'.csv';
        Storage::disk('local')->put($path, 'placeholder');
        ImportBatch::query()->create([
            'module' => 'sales-invoice-history', 'status' => ImportBatchStatus::COMPLETED,
            'original_filename' => 'test.csv', 'disk' => 'local', 'file_path' => $path,
        ]);

        return $invoice;
    }

    public function test_commit_deletes_invoice_items_ar_and_import_batches(): void
    {
        $invoice = $this->makeHistoricalInvoice('SI/KE/00001/09/2026');

        $this->artisan('sales-invoice-import:purge', ['--commit' => true])->assertExitCode(0);

        $this->assertNull(Invoice::query()->find($invoice->id));
        $this->assertSame(0, AccountsReceivable::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(0, ImportBatch::query()->where('module', 'sales-invoice-history')->count());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $invoice = $this->makeHistoricalInvoice('SI/KE/00001/09/2026');

        $this->artisan('sales-invoice-import:purge')->assertExitCode(0);

        $this->assertNotNull(Invoice::query()->find($invoice->id));
        $this->assertSame(1, AccountsReceivable::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(1, ImportBatch::query()->where('module', 'sales-invoice-history')->count());
    }

    public function test_leaves_manually_created_invoices_alone(): void
    {
        $manual = Invoice::query()->create([
            'customer_id' => $this->customer->id,
            'invoice_type' => 'goods',
            'invoice_date' => '2026-09-30',
            'due_date' => '2026-09-30',
            'subtotal' => 50000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 50000,
        ]);

        $this->artisan('sales-invoice-import:purge', ['--commit' => true])->assertExitCode(0);

        $this->assertNotNull(Invoice::query()->find($manual->id));
    }

    /** Guards the exact collision this command must never cause: CustomerOutstandingArchiveImportService
        deliberately uses a different import_source_type ('outstanding_bills_archive') precisely so
        a run of this command can never delete its legitimate new Invoices. */
    public function test_leaves_outstanding_bills_archive_invoices_alone(): void
    {
        $fromNewFeature = Invoice::query()->create([
            'customer_id' => $this->customer->id,
            'document_number' => 'SI/KE/00099/09/2026',
            'invoice_type' => 'goods',
            'invoice_date' => '2026-09-30',
            'due_date' => '2026-10-30',
            'subtotal' => 100000, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => 100000,
            'source_document_number' => 'SI/KE/00099/09/2026',
            'import_source_type' => 'outstanding_bills_archive',
        ]);

        $this->artisan('sales-invoice-import:purge', ['--commit' => true])->assertExitCode(0);

        $this->assertNotNull(Invoice::query()->find($fromNewFeature->id));
    }

    public function test_an_invoice_with_a_real_payment_allocation_is_skipped_not_force_deleted(): void
    {
        $paid = $this->makeHistoricalInvoice('SI/KE/00001/09/2026');
        $unpaid = $this->makeHistoricalInvoice('SI/KE/00002/09/2026');

        $receipt = ReceiptEntry::query()->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => '2026-10-01',
            'payment_method' => 'cash',
        ]);
        PaymentAllocation::query()->create([
            'receipt_entry_id' => $receipt->id,
            'accounts_receivable_id' => $paid->accountsReceivable->id,
            'allocated_amount' => 111000,
            'allocation_date' => '2026-10-01',
        ]);

        $this->artisan('sales-invoice-import:purge', ['--commit' => true])->assertExitCode(0);

        $this->assertNotNull(Invoice::query()->find($paid->id), 'the invoice with a real allocation must survive');
        $this->assertNull(Invoice::query()->find($unpaid->id), 'the unblocked invoice must still be deleted');
    }
}
