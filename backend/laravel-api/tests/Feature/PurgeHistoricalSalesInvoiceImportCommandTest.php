<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Enums\WarehouseType;
use App\Models\AccountsReceivable;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\PaymentAllocation;
use App\Models\ReceiptEntry;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\Import\SalesInvoiceImportService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Exercises the SI historical-import rollback — see PurgeHistoricalSalesInvoiceImportCommand's own docblock. */
class PurgeHistoricalSalesInvoiceImportCommandTest extends TestCase
{
    use RefreshDatabase;

    protected SalesInvoiceImportService $service;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DocumentEngineSeeder::class);

        $this->service = app(SalesInvoiceImportService::class);
        Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'CUST1', 'customer_name' => 'Test Customer']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak', 'symbol' => 'ZAK']);
        Item::query()->create(['item_code' => 'ITEM1', 'item_name' => 'Test Item', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 0]);
    }

    private const PREAMBLE = [
        ['SALES INVOICE LISTING - DETAIL'],
        ['31/08/2026 - 30/09/2026 - Base Currency'],
        ['', '', '', '', '', '', '', '', '', '', '', '', ''],
        ['', '', '', '', '', '', '', '', '', '', '', '', ''],
        ['PT. KALINDO ETAM', '', '', '30/09/2026 15:33:31', '', '', '', '', '', '', '', '', ''],
        ['', '', '', '', '', '', '', '', '', '', '', '', ''],
        ['', '', '', '', '', '', '', '', '', '', '', '', ''],
        ['DATE', 'DOCUMENT #', 'CUSTOMER#', 'NAME', '', 'DELIVERY TO', '', 'DISC', 'TAX', 'T.CODE', 'AMOUNT', 'REFERENCE 1 #', 'REFERENCE 2 #'],
        ['ITEM # ', '', 'DESCRIPTION', '', 'UOM', 'QUANTITY', 'UNIT PRICE', 'DISC', 'TAX', 'T.CODE', 'LINE AMOUNT', '', ''],
    ];

    private function csv(array $rows): string
    {
        return implode("\r\n", array_map(
            fn (array $row) => implode(',', array_map(fn ($v) => $v ?? '', $row)),
            $rows
        ))."\r\n";
    }

    private function importHistoricalInvoice(string $documentNumber): Invoice
    {
        $csv = $this->csv([
            ...self::PREAMBLE,
            ['30/09/2026', $documentNumber, 'CUST1', 'Test Customer', '', '', '', 0, 11000, '', 111000, '', ''],
            ['ITEM1', '', 'Test Item', '', 'ZAK', 10, 10000, 0, 11000, '', 111000, '', ''],
        ]);

        $path = 'imports/test-'.uniqid().'.csv';
        Storage::disk('local')->put($path, $csv);
        $batch = ImportBatch::query()->create([
            'module' => 'sales-invoice-history', 'status' => ImportBatchStatus::QUEUED,
            'original_filename' => 'test.csv', 'disk' => 'local', 'file_path' => $path,
        ]);

        $this->service->import($batch);

        return Invoice::query()->where('source_document_number', $documentNumber)->firstOrFail();
    }

    public function test_commit_deletes_invoice_items_ar_and_import_batches(): void
    {
        $invoice = $this->importHistoricalInvoice('SI/KE/00001/09/2026');

        $this->artisan('sales-invoice-import:purge', ['--commit' => true])->assertExitCode(0);

        $this->assertNull(Invoice::query()->find($invoice->id));
        $this->assertSame(0, AccountsReceivable::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(0, ImportBatch::query()->where('module', 'sales-invoice-history')->count());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $invoice = $this->importHistoricalInvoice('SI/KE/00001/09/2026');

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

    public function test_an_invoice_with_a_real_payment_allocation_is_skipped_not_force_deleted(): void
    {
        $paid = $this->importHistoricalInvoice('SI/KE/00001/09/2026');
        $unpaid = $this->importHistoricalInvoice('SI/KE/00002/09/2026');

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
