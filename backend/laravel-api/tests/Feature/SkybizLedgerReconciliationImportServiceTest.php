<?php

namespace Tests\Feature;

use App\Enums\AccountsReceivableStatus;
use App\Enums\DocumentStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\InvoiceType;
use App\Models\AccountsReceivable;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\ReceiptEntry;
use App\Repositories\BankReconciliationRepository;
use App\Services\Import\SkybizLedgerReconciliationImportService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the ticket's own documented edge cases (prompt-import-pelunasan-skybiz.md): FIFO vs
 * explicit-match allocation, multi-invoice CB rows, GJ routed to the migration suspense account
 * instead of a real bank, GJ reversals never auto-applied, unparseable/ambiguous refs, never
 * lowering paid_amount, idempotent re-runs, and the Bank Reconciliation isolation the user
 * specifically asked to be verified.
 */
class SkybizLedgerReconciliationImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SkybizLedgerReconciliationImportService $service;

    private const PREAMBLE = "Debtors Ledger For (Test)\r\nPT TEST\r\n01/01/2020 - 03/10/2026,,,03/10/2026 20:19:26\r\n\r\n";

    private const HEADER = "Date,Particulars,Inv/Chq #,TranType,Batch #,Debit,Credit,Balance\r\n";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        ChartOfAccount::query()->create(['code' => '1101', 'name' => 'BANK BCA 1312', 'account_type' => 'asset', 'is_active' => true, 'is_cash_bank' => true]);
        ChartOfAccount::query()->create(['code' => '1102', 'name' => 'KAS BESAR SAMARINDA', 'account_type' => 'asset', 'is_active' => true, 'is_cash_bank' => true]);

        $this->service = app(SkybizLedgerReconciliationImportService::class);
    }

    private function makeCustomer(string $code): Customer
    {
        return Customer::query()->create(['customer_code' => $code, 'customer_name' => "Customer {$code}"]);
    }

    private function makeInvoice(Customer $customer, string $documentNumber, float $amount, string $date = '2026-08-01'): Invoice
    {
        return Invoice::query()->create([
            'document_number' => $documentNumber,
            'customer_id' => $customer->id,
            'invoice_type' => str_starts_with($documentNumber, 'TR') ? InvoiceType::TRANSPORTATION->value : InvoiceType::GOODS->value,
            'status' => DocumentStatus::SUBMITTED->value,
            'invoice_date' => $date,
            'due_date' => $date,
            'subtotal' => $amount,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => $amount,
        ]);
    }

    private function makeReceivable(Invoice $invoice, float $amount, float $paidAmount = 0.0): AccountsReceivable
    {
        return AccountsReceivable::query()->create([
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'reference_number' => $invoice->document_number,
            'amount' => $amount,
            'paid_amount' => $paidAmount,
            'due_date' => $invoice->due_date,
            'status' => $paidAmount >= $amount ? AccountsReceivableStatus::PAID : ($paidAmount > 0 ? AccountsReceivableStatus::PARTIALLY_PAID : AccountsReceivableStatus::UNPAID),
        ]);
    }

    private function makeBatch(string $csv): ImportBatch
    {
        $path = 'imports/skybiz-test-'.uniqid().'.csv';
        Storage::disk('local')->put($path, $csv);

        return ImportBatch::query()->create([
            'module' => 'skybiz-ledger-reconciliation',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => 'test.csv',
            'disk' => 'local',
            'file_path' => $path,
        ]);
    }

    public function test_cb_row_fully_pays_a_single_invoice_via_real_bank_account(): void
    {
        $customer = $this->makeCustomer('C-0001');
        $invoice = $this->makeInvoice($customer, 'SI/KE/00001/08/2026', 1000000);
        $ar = $this->makeReceivable($invoice, 1000000);

        $csv = self::PREAMBLE.self::HEADER
            ."C-0001 - Customer C-0001,,,,,,,\r\n"
            ."01/08/2026,Sales,SI/KE/00001/08/2026,SJ,,1000000,0,1000000\r\n"
            .'15/08/2026,"PIUTANG USAHA, BANK BCA 1312",OR/KE/00001/08/2026,CB,,0,1000000,0'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->run($batch, true);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status);
        $this->assertSame(1, $batch->preview_summary['or_to_create']);

        $entry = ReceiptEntry::query()->where('reference_number', 'OR/KE/00001/08/2026')->firstOrFail();
        $this->assertSame('submitted', $entry->status->value);
        $this->assertEquals(1000000, (float) $entry->total_amount);
        $this->assertSame('bank_transfer', $entry->payment_method->value);
        $this->assertSame('BANK BCA 1312', $entry->cashAccount->name);
        $this->assertSame('import', $entry->source);

        $this->assertEquals(1000000, (float) $ar->refresh()->paid_amount);
        $this->assertSame('paid', $ar->status->value);
    }

    public function test_cash_particulars_infers_cash_payment_method(): void
    {
        $customer = $this->makeCustomer('C-0002');
        $invoice = $this->makeInvoice($customer, 'SI/KE/00002/08/2026', 100000);
        $this->makeReceivable($invoice, 100000);

        $csv = self::PREAMBLE.self::HEADER
            ."C-0002 - Customer C-0002,,,,,,,\r\n"
            ."02/08/2026,Sales,SI/KE/00002/08/2026,SJ,,100000,0,100000\r\n"
            .'16/08/2026,"PIUTANG USAHA, KAS BESAR SAMARINDA",OR/KE/00002/08/2026,CB,,0,100000,0'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->run($batch, true);

        $entry = ReceiptEntry::query()->where('reference_number', 'OR/KE/00002/08/2026')->firstOrFail();
        $this->assertSame('cash', $entry->payment_method->value);
    }

    public function test_one_cb_row_explicitly_splits_across_two_invoices(): void
    {
        $customer = $this->makeCustomer('C-0003');
        $siInvoice = $this->makeInvoice($customer, 'SI/KE/00003/08/2026', 400000);
        $trInvoice = $this->makeInvoice($customer, 'TR/KE/00004/08/2026', 600000);
        $siAr = $this->makeReceivable($siInvoice, 400000);
        $trAr = $this->makeReceivable($trInvoice, 600000);

        $csv = self::PREAMBLE.self::HEADER
            ."C-0003 - Customer C-0003,,,,,,,\r\n"
            ."03/08/2026,Sales,SI/KE/00003/08/2026,SJ,,400000,0,400000\r\n"
            ."03/08/2026,Sales,TR/KE/00004/08/2026,SJ,,600000,0,1000000\r\n"
            .'20/08/2026,"Pelunasan SI/KE/00003/08/2026 dan TR/KE/00004/08/2026, BANK BCA 1312",OR/KE/00003/08/2026,CB,,0,1000000,0'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->run($batch, true);

        $entry = ReceiptEntry::query()->where('reference_number', 'OR/KE/00003/08/2026')->firstOrFail();
        $this->assertEquals(1000000, (float) $entry->total_amount);
        $this->assertEquals(400000, (float) $siAr->refresh()->paid_amount);
        $this->assertEquals(600000, (float) $trAr->refresh()->paid_amount);
    }

    public function test_gj_credit_row_is_routed_to_the_migration_suspense_account(): void
    {
        $customer = $this->makeCustomer('C-0004');
        $invoice = $this->makeInvoice($customer, 'SI/KE/00005/08/2026', 50000);
        $ar = $this->makeReceivable($invoice, 50000);

        $csv = self::PREAMBLE.self::HEADER
            ."C-0004 - Customer C-0004,,,,,,,\r\n"
            ."04/08/2026,Sales,SI/KE/00005/08/2026,SJ,,50000,0,50000\r\n"
            ."21/08/2026,PIUTANG USAHA pembulatan,GJ/KE/00001/08/2026,GJ,,0,50000,0\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->run($batch, true);

        $entry = ReceiptEntry::query()->where('reference_number', 'GJ/KE/00001/08/2026')->firstOrFail();
        $suspense = ChartOfAccount::query()->where('code', '1199')->firstOrFail();

        $this->assertSame($suspense->id, $entry->cash_account_id);
        $this->assertSame('cash', $entry->payment_method->value);
        $this->assertStringContainsString('GJ (jurnal penyesuaian)', $entry->remarks);
        $this->assertEquals(50000, (float) $ar->refresh()->paid_amount);
    }

    public function test_gj_debit_row_is_never_auto_applied(): void
    {
        $customer = $this->makeCustomer('C-0005');
        $invoice = $this->makeInvoice($customer, 'SI/KE/00006/08/2026', 50000);
        $ar = $this->makeReceivable($invoice, 50000, 50000);

        $csv = self::PREAMBLE.self::HEADER
            ."C-0005 - Customer C-0005,,,,,,,\r\n"
            ."05/08/2026,Sales,SI/KE/00006/08/2026,SJ,,50000,0,50000\r\n"
            ."22/08/2026,Reversal pembayaran,GJ/KE/00002/08/2026,GJ,,20000,0,70000\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->run($batch, true);
        $batch->refresh();

        $this->assertSame(1, $batch->preview_summary['reversal_rows_needs_review']);
        $this->assertSame(0, ReceiptEntry::query()->count());
        $this->assertEquals(50000, (float) $ar->refresh()->paid_amount);
    }

    /** Found in production: a backfilled historical invoice can be Cancelled yet still carry an AR row. */
    public function test_cancelled_invoice_is_skipped_not_auto_corrected(): void
    {
        $customer = $this->makeCustomer('C-0013');
        $invoice = $this->makeInvoice($customer, 'SI/KE/00013/08/2026', 100000);
        $invoice->update(['status' => DocumentStatus::CANCELLED->value]);
        $ar = $this->makeReceivable($invoice, 100000);

        $csv = self::PREAMBLE.self::HEADER
            ."C-0013 - Customer C-0013,,,,,,,\r\n"
            ."13/08/2026,Sales,SI/KE/00013/08/2026,SJ,,100000,0,100000\r\n"
            .'27/08/2026,"PIUTANG USAHA, BANK BCA 1312",OR/KE/00013/08/2026,CB,,0,100000,0'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->run($batch, true);
        $batch->refresh();

        $this->assertSame(1, $batch->preview_summary['invoice_cancelled_skipped']);
        $this->assertSame(0, ReceiptEntry::query()->count());
        $this->assertEquals(0, (float) $ar->refresh()->paid_amount);
    }

    public function test_unparseable_reference_is_reported_not_crashed(): void
    {
        $customer = $this->makeCustomer('C-0006');

        $csv = self::PREAMBLE.self::HEADER
            ."C-0006 - Customer C-0006,,,,,,,\r\n"
            ."06/08/2026,Sales lama,300920222,SJ,,75000,0,75000\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->run($batch, true);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status);
        $this->assertSame(1, $batch->preview_summary['unmatched_unparseable_ref']);
    }

    /** The ticket's spot-check requirement — flagged rows must be reviewable before anyone presses "Jalankan Import", not only after. */
    public function test_preview_mode_already_attaches_a_downloadable_review_csv(): void
    {
        $customer = $this->makeCustomer('C-0006b');

        $csv = self::PREAMBLE.self::HEADER
            ."C-0006b - Customer C-0006b,,,,,,,\r\n"
            ."06/08/2026,Sales lama,300920222,SJ,,75000,0,75000\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->run($batch, false);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::PREVIEWED, $batch->status);
        $this->assertNotNull($batch->error_report_path);
        $this->assertTrue(Storage::disk('local')->exists($batch->error_report_path));
        $this->assertStringContainsString('unmatched_unparseable_ref', Storage::disk('local')->get($batch->error_report_path));
    }

    public function test_ke_paid_amount_higher_than_ledger_is_never_decreased(): void
    {
        $customer = $this->makeCustomer('C-0007');
        $invoice = $this->makeInvoice($customer, 'SI/KE/00007/08/2026', 500000);
        $ar = $this->makeReceivable($invoice, 500000, 400000);

        $csv = self::PREAMBLE.self::HEADER
            ."C-0007 - Customer C-0007,,,,,,,\r\n"
            ."07/08/2026,Sales,SI/KE/00007/08/2026,SJ,,500000,0,500000\r\n"
            .'23/08/2026,"PIUTANG USAHA, BANK BCA 1312",OR/KE/00008/08/2026,CB,,0,300000,200000'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->run($batch, true);
        $batch->refresh();

        $this->assertSame(1, $batch->preview_summary['ke_higher_than_skybiz_conflict']);
        $this->assertSame(0, ReceiptEntry::query()->count());
        $this->assertEquals(400000, (float) $ar->refresh()->paid_amount);
    }

    public function test_preview_mode_writes_nothing(): void
    {
        $customer = $this->makeCustomer('C-0009');
        $invoice = $this->makeInvoice($customer, 'SI/KE/00009/08/2026', 100000);
        $this->makeReceivable($invoice, 100000);

        $csv = self::PREAMBLE.self::HEADER
            ."C-0009 - Customer C-0009,,,,,,,\r\n"
            ."09/08/2026,Sales,SI/KE/00009/08/2026,SJ,,100000,0,100000\r\n"
            .'24/08/2026,"PIUTANG USAHA, BANK BCA 1312",OR/KE/00009/08/2026,CB,,0,100000,0'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->run($batch, false);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::PREVIEWED, $batch->status);
        $this->assertSame(1, $batch->preview_summary['or_to_create']);
        $this->assertSame(1, $batch->preview_summary['invoices_to_correct']);
        $this->assertSame(0, ReceiptEntry::query()->count());
    }

    public function test_rerunning_commit_on_the_same_file_does_not_duplicate(): void
    {
        $customer = $this->makeCustomer('C-0010');
        $invoice = $this->makeInvoice($customer, 'SI/KE/00010/08/2026', 100000);
        $ar = $this->makeReceivable($invoice, 100000);

        $csv = self::PREAMBLE.self::HEADER
            ."C-0010 - Customer C-0010,,,,,,,\r\n"
            ."10/08/2026,Sales,SI/KE/00010/08/2026,SJ,,100000,0,100000\r\n"
            .'25/08/2026,"PIUTANG USAHA, BANK BCA 1312",OR/KE/00010/08/2026,CB,,0,100000,0'."\r\n";

        $firstBatch = $this->makeBatch($csv);
        $this->service->run($firstBatch, true);
        $this->assertSame(1, ReceiptEntry::query()->where('reference_number', 'OR/KE/00010/08/2026')->count());
        $this->assertEquals(100000, (float) $ar->refresh()->paid_amount);

        // Re-running the exact same file: paid_amount is already in sync, so this invoice never
        // even reaches the reference-based idempotency check — it's recognized as up to date from
        // the amount comparison alone (ticket's "never lower paid_amount" invariant, the other
        // direction).
        $secondBatch = $this->makeBatch($csv);
        $this->service->run($secondBatch, true);
        $secondBatch->refresh();

        $this->assertSame(1, ReceiptEntry::query()->where('reference_number', 'OR/KE/00010/08/2026')->count());
        $this->assertSame(0, $secondBatch->preview_summary['or_to_create']);
        $this->assertSame(1, $secondBatch->preview_summary['invoices_matched_up_to_date']);
        $this->assertEquals(100000, (float) $ar->refresh()->paid_amount);
    }

    /**
     * The narrower idempotency path: a Skybiz reference was already imported, but the invoice it
     * touches hasn't (yet) had its paid_amount reflect that — e.g. a previous run partially failed.
     * The reference-based check (ReceiptEntry.reference_number_normalized) must still catch this
     * even though the amount-comparison short-circuit above doesn't.
     */
    public function test_an_already_imported_reference_is_skipped_even_if_paid_amount_was_not_yet_corrected(): void
    {
        $customer = $this->makeCustomer('C-0012');
        $invoice = $this->makeInvoice($customer, 'SI/KE/00012/08/2026', 100000);
        $ar = $this->makeReceivable($invoice, 100000, 0);

        ReceiptEntry::query()->create([
            'customer_id' => $customer->id,
            'receipt_date' => '2026-08-25',
            'cash_account_id' => ChartOfAccount::query()->where('code', '1101')->firstOrFail()->id,
            'reference_number' => 'OR/KE/00012/08/2026',
            'total_amount' => 100000,
            'payment_method' => 'bank_transfer',
        ]);

        $csv = self::PREAMBLE.self::HEADER
            ."C-0012 - Customer C-0012,,,,,,,\r\n"
            ."12/08/2026,Sales,SI/KE/00012/08/2026,SJ,,100000,0,100000\r\n"
            .'25/08/2026,"PIUTANG USAHA, BANK BCA 1312",OR/KE/00012/08/2026,CB,,0,100000,0'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->run($batch, true);
        $batch->refresh();

        $this->assertSame(1, ReceiptEntry::query()->where('reference_number', 'OR/KE/00012/08/2026')->count());
        $this->assertSame(1, $batch->preview_summary['already_imported']);
        $this->assertSame(0, $batch->preview_summary['or_to_create']);
        $this->assertEquals(0, (float) $ar->refresh()->paid_amount);
    }

    /** The specific safety check the user asked to have verified before building this (see plan). */
    public function test_gj_sourced_receipt_never_surfaces_under_a_real_banks_reconciliation(): void
    {
        $customer = $this->makeCustomer('C-0011');
        $invoice = $this->makeInvoice($customer, 'SI/KE/00011/08/2026', 30000, '2026-08-11');
        $this->makeReceivable($invoice, 30000);

        $csv = self::PREAMBLE.self::HEADER
            ."C-0011 - Customer C-0011,,,,,,,\r\n"
            ."11/08/2026,Sales,SI/KE/00011/08/2026,SJ,,30000,0,30000\r\n"
            ."26/08/2026,PIUTANG USAHA pembulatan,GJ/KE/00003/08/2026,GJ,,0,30000,0\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->run($batch, true);

        $entry = ReceiptEntry::query()->where('reference_number', 'GJ/KE/00003/08/2026')->firstOrFail();
        $suspense = ChartOfAccount::query()->where('code', '1199')->firstOrFail();
        $realBank = ChartOfAccount::query()->where('code', '1101')->firstOrFail();

        // Mirrors exactly what BankReconciliationService::matchingRows() does internally:
        // cashBookRows() is unfiltered, the per-account Detail tab filters by bank_account_id.
        $date = $entry->receipt_date->format('Y-m-d');
        $cashBookRows = collect(app(BankReconciliationRepository::class)->cashBookRows($date, $date));

        $realBankRows = $cashBookRows->filter(fn ($row) => $row['bank_account_id'] === $realBank->id);
        $this->assertFalse($realBankRows->contains(fn ($row) => $row['document_number'] === $entry->document_number));

        $suspenseRows = $cashBookRows->filter(fn ($row) => $row['bank_account_id'] === $suspense->id);
        $this->assertTrue($suspenseRows->contains(fn ($row) => $row['document_number'] === $entry->document_number));
    }
}
