<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Enums\WarehouseType;
use App\Models\AccountsReceivable;
use App\Models\Customer;
use App\Models\CustomerOutstandingSnapshot;
use App\Models\CustomerOutstandingSnapshotLine;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\Import\SalesInvoiceImportService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Simulates the pre-fix database: a historical-imported Invoice with no AccountsReceivable row at
 * all, exactly how SalesInvoiceImportService left every row before InvoiceService::submit() was
 * fixed to always create one (2026-10-02). Builds the Invoice via the real import service (so it's
 * a realistic row), then deletes the AR row the now-fixed submit() already created, to reproduce
 * the old broken state for the command to repair.
 */
class BackfillHistoricalInvoiceAccountsReceivableCommandTest extends TestCase
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

    /** Imports one historical Invoice (SI/KE/00001/09/2026, Rp111.000), then strips its AR row to reproduce the pre-fix state. */
    private function importHistoricalInvoiceWithoutAr(): Invoice
    {
        $csv = $this->csv([
            ...self::PREAMBLE,
            ['30/09/2026', 'SI/KE/00001/09/2026', 'CUST1', 'Test Customer', '', '', '', 0, 11000, '', 111000, '', ''],
            ['ITEM1', '', 'Test Item', '', 'ZAK', 10, 10000, 0, 11000, '', 111000, '', ''],
        ]);
        $path = 'imports/test-'.uniqid().'.csv';
        Storage::disk('local')->put($path, $csv);
        $batch = ImportBatch::query()->create([
            'module' => 'sales-invoice-history', 'status' => ImportBatchStatus::QUEUED,
            'original_filename' => 'test.csv', 'disk' => 'local', 'file_path' => $path,
        ]);

        $this->service->import($batch);

        $invoice = Invoice::query()->where('source_document_number', 'SI/KE/00001/09/2026')->firstOrFail();
        $invoice->accountsReceivable()->delete();

        return $invoice;
    }

    public function test_creates_ar_seeded_from_a_matching_snapshot_line(): void
    {
        $invoice = $this->importHistoricalInvoiceWithoutAr();

        $snapshot = CustomerOutstandingSnapshot::query()->create([
            'source_filename' => 'xlsCustomerOutstandingBills.xlsx', 'snapshot_as_of_date' => '2026-09-30',
            'total_rows' => 1, 'total_customers' => 1, 'grand_total_unpaid' => 50000, 'grand_total_overdue' => 0,
        ]);
        CustomerOutstandingSnapshotLine::query()->create([
            'snapshot_id' => $snapshot->id, 'customer_code' => 'CUST1', 'customer_name' => 'Test Customer',
            'txn_date' => '2026-09-30', 'ref_no' => 'SI/KE/00001/09/2026', 'invoice_amount' => 111000,
            'paid_amount' => 61000, 'unpaid_amount' => 50000, 'due_date' => '2026-10-30', 'overdue_amount' => 0, 'overdue_days' => 0,
        ]);

        $this->artisan('ar:backfill-historical-invoices')->assertExitCode(0);

        $ar = AccountsReceivable::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertEquals(111000, (float) $ar->amount);
        $this->assertEquals(61000, (float) $ar->paid_amount);
        $this->assertSame('partially_paid', $ar->status->value);
    }

    public function test_invoice_with_no_matching_snapshot_line_starts_fully_outstanding(): void
    {
        $invoice = $this->importHistoricalInvoiceWithoutAr();

        $this->artisan('ar:backfill-historical-invoices')->assertExitCode(0);

        $ar = AccountsReceivable::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertEquals(0, (float) $ar->paid_amount);
        $this->assertSame('unpaid', $ar->status->value);
    }

    public function test_the_most_recent_snapshot_wins_when_the_same_ref_no_appears_in_more_than_one(): void
    {
        $invoice = $this->importHistoricalInvoiceWithoutAr();

        $older = CustomerOutstandingSnapshot::query()->create([
            'source_filename' => 'old.xlsx', 'snapshot_as_of_date' => '2026-09-15',
            'total_rows' => 1, 'total_customers' => 1, 'grand_total_unpaid' => 111000, 'grand_total_overdue' => 0,
        ]);
        CustomerOutstandingSnapshotLine::query()->create([
            'snapshot_id' => $older->id, 'customer_code' => 'CUST1', 'customer_name' => 'Test Customer',
            'txn_date' => '2026-09-01', 'ref_no' => 'SI/KE/00001/09/2026', 'invoice_amount' => 111000,
            'paid_amount' => 0, 'unpaid_amount' => 111000, 'due_date' => '2026-10-30', 'overdue_amount' => 0, 'overdue_days' => 0,
        ]);

        $newer = CustomerOutstandingSnapshot::query()->create([
            'source_filename' => 'new.xlsx', 'snapshot_as_of_date' => '2026-09-30',
            'total_rows' => 1, 'total_customers' => 1, 'grand_total_unpaid' => 0, 'grand_total_overdue' => 0,
        ]);
        CustomerOutstandingSnapshotLine::query()->create([
            'snapshot_id' => $newer->id, 'customer_code' => 'CUST1', 'customer_name' => 'Test Customer',
            'txn_date' => '2026-09-01', 'ref_no' => 'SI/KE/00001/09/2026', 'invoice_amount' => 111000,
            'paid_amount' => 111000, 'unpaid_amount' => 0, 'due_date' => '2026-10-30', 'overdue_amount' => 0, 'overdue_days' => 0,
        ]);

        $this->artisan('ar:backfill-historical-invoices')->assertExitCode(0);

        $ar = AccountsReceivable::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertEquals(111000, (float) $ar->paid_amount, 'the newer snapshot (30 Sep) must win over the older one (15 Sep)');
        $this->assertSame('paid', $ar->status->value);
    }

    public function test_dry_run_reports_but_does_not_save(): void
    {
        $this->importHistoricalInvoiceWithoutAr();

        $this->artisan('ar:backfill-historical-invoices', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, AccountsReceivable::query()->count());
    }

    public function test_invoice_that_already_has_an_ar_row_is_left_untouched(): void
    {
        $csv = $this->csv([
            ...self::PREAMBLE,
            ['30/09/2026', 'SI/KE/00002/09/2026', 'CUST1', 'Test Customer', '', '', '', 0, 0, '', 100000, '', ''],
            ['ITEM1', '', 'Test Item', '', 'ZAK', 10, 10000, 0, 0, '', 100000, '', ''],
        ]);
        $path = 'imports/test-'.uniqid().'.csv';
        Storage::disk('local')->put($path, $csv);
        $batch = ImportBatch::query()->create([
            'module' => 'sales-invoice-history', 'status' => ImportBatchStatus::QUEUED,
            'original_filename' => 'test.csv', 'disk' => 'local', 'file_path' => $path,
        ]);
        $this->service->import($batch);

        // Not stripped this time — submit() already created the AR row (the fix under test).
        $invoice = Invoice::query()->where('source_document_number', 'SI/KE/00002/09/2026')->firstOrFail();
        $originalArId = $invoice->accountsReceivable->id;

        $this->artisan('ar:backfill-historical-invoices')->assertExitCode(0);

        $this->assertSame(1, AccountsReceivable::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame($originalArId, $invoice->accountsReceivable()->first()->id);
    }

    public function test_running_twice_is_idempotent(): void
    {
        $invoice = $this->importHistoricalInvoiceWithoutAr();

        $this->artisan('ar:backfill-historical-invoices')->assertExitCode(0);
        $this->artisan('ar:backfill-historical-invoices')->assertExitCode(0);

        $this->assertSame(1, AccountsReceivable::query()->where('invoice_id', $invoice->id)->count());
    }
}
