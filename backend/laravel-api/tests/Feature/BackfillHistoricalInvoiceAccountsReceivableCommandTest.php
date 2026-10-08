<?php

namespace Tests\Feature;

use App\Models\AccountsReceivable;
use App\Models\Customer;
use App\Models\CustomerOutstandingSnapshot;
use App\Models\CustomerOutstandingSnapshotLine;
use App\Models\Invoice;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Simulates the pre-fix database: a historical-imported Invoice with no AccountsReceivable row at
 * all, exactly how the (now-removed) Sales Invoice history importer left every row before
 * InvoiceService::submit() was fixed to always create one (2026-10-02). Invoices are built
 * directly via Eloquent (that importer was removed 2026-10-08, superseded by
 * CustomerOutstandingArchiveImportService) rather than through any import flow; this command only
 * cares about import_source_type and the presence of an AccountsReceivable row, not how either
 * came to be.
 */
class BackfillHistoricalInvoiceAccountsReceivableCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->customer = Customer::query()->create(['customer_code' => 'CUST1', 'customer_name' => 'Test Customer']);
    }

    private function makeHistoricalInvoice(string $documentNumber, float $amount, bool $withAr): Invoice
    {
        $invoice = Invoice::query()->create([
            'customer_id' => $this->customer->id,
            'document_number' => $documentNumber,
            'invoice_type' => 'goods',
            'invoice_date' => '2026-09-30',
            'due_date' => '2026-10-30',
            'subtotal' => $amount, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => $amount,
            'source_document_number' => $documentNumber,
            'import_source_type' => 'historical_invoice',
        ]);

        if ($withAr) {
            AccountsReceivable::query()->create([
                'customer_id' => $this->customer->id, 'invoice_id' => $invoice->id,
                'reference_number' => $documentNumber, 'amount' => $amount, 'paid_amount' => 0,
                'due_date' => '2026-10-30', 'status' => 'unpaid',
            ]);
        }

        return $invoice;
    }

    public function test_creates_ar_seeded_from_a_matching_snapshot_line(): void
    {
        $invoice = $this->makeHistoricalInvoice('SI/KE/00001/09/2026', 111000, withAr: false);

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
        $invoice = $this->makeHistoricalInvoice('SI/KE/00001/09/2026', 111000, withAr: false);

        $this->artisan('ar:backfill-historical-invoices')->assertExitCode(0);

        $ar = AccountsReceivable::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertEquals(0, (float) $ar->paid_amount);
        $this->assertSame('unpaid', $ar->status->value);
    }

    public function test_the_most_recent_snapshot_wins_when_the_same_ref_no_appears_in_more_than_one(): void
    {
        $invoice = $this->makeHistoricalInvoice('SI/KE/00001/09/2026', 111000, withAr: false);

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
        $this->makeHistoricalInvoice('SI/KE/00001/09/2026', 111000, withAr: false);

        $this->artisan('ar:backfill-historical-invoices', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, AccountsReceivable::query()->count());
    }

    public function test_invoice_that_already_has_an_ar_row_is_left_untouched(): void
    {
        $invoice = $this->makeHistoricalInvoice('SI/KE/00002/09/2026', 100000, withAr: true);
        $originalArId = $invoice->accountsReceivable->id;

        $this->artisan('ar:backfill-historical-invoices')->assertExitCode(0);

        $this->assertSame(1, AccountsReceivable::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame($originalArId, $invoice->accountsReceivable()->first()->id);
    }

    public function test_running_twice_is_idempotent(): void
    {
        $invoice = $this->makeHistoricalInvoice('SI/KE/00001/09/2026', 111000, withAr: false);

        $this->artisan('ar:backfill-historical-invoices')->assertExitCode(0);
        $this->artisan('ar:backfill-historical-invoices')->assertExitCode(0);

        $this->assertSame(1, AccountsReceivable::query()->where('invoice_id', $invoice->id)->count());
    }
}
