<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\JournalEntry;
use App\Services\Import\SalesPurchaseJournalImportService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Exercises SalesPurchaseJournalImportService end-to-end against a CSV shaped like the real
 * legacy Sales/Purchase Journal export (11 columns: Transaction/Date/Ref. 1 #/Particulars/Debit/
 * Credit/Tax Code/Salesman Code/Department Code/Project Code/Branch Code — verified against the
 * real xlsJournalList (2)/(3).xlsx sample files). Unlike CashBookImportService, this posts raw
 * Journal Entries only — no PaymentEntry/ReceiptEntry/Invoice/etc — see the service's own
 * docblock for why.
 */
class SalesPurchaseJournalImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SalesPurchaseJournalImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DocumentEngineSeeder::class);

        ChartOfAccount::query()->create(['code' => '112.01.01', 'name' => 'PIUTANG USAHA', 'account_type' => 'asset', 'is_active' => true]);
        ChartOfAccount::query()->create(['code' => '410.01.02', 'name' => 'PENJUALAN KREDIT', 'account_type' => 'revenue', 'is_active' => true]);
        ChartOfAccount::query()->create(['code' => '219.01.01', 'name' => 'HUTANG PPN', 'account_type' => 'liability', 'is_active' => true]);
        ChartOfAccount::query()->create(['code' => '210.01.01', 'name' => 'HUTANG SUPPLIER', 'account_type' => 'liability', 'is_active' => true]);
        ChartOfAccount::query()->create(['code' => '510.01.02', 'name' => 'PEMBELIAN', 'account_type' => 'expense', 'is_active' => true]);
        $company = Company::query()->create(['name' => 'PT. KALINDO ETAM', 'code' => 'KE', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Balikpapan', 'code' => 'BPP', 'is_active' => true]);

        $this->service = app(SalesPurchaseJournalImportService::class);
    }

    private function makeBatch(string $csv, string $view, string $duplicatePolicy = 'skip'): ImportBatch
    {
        $path = 'imports/test.csv';
        Storage::disk('local')->put($path, $csv);

        return ImportBatch::query()->create([
            'module' => 'sales-purchase-journal',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => 'test.csv',
            'disk' => 'local',
            'file_path' => $path,
            'mapping' => ['view' => $view],
            'write_mode' => $duplicatePolicy,
        ]);
    }

    private const PREAMBLE = "JOURNAL LIST\r\nPT. KALINDO ETAM\r\n01/01/2022 - 31/12/2025,,,,17/09/2026 10:09:44\r\n\r\n";

    private const HEADER = "Transaction,Date,Ref. 1 #,Particulars,Debit,Credit,Tax Code,Salesman Code,Department Code,Project Code,Branch Code\r\n";

    public function test_sales_invoice_group_posts_a_balanced_journal_entry(): void
    {
        $csv = self::PREAMBLE.self::HEADER
            .'Sales Journal,,,,,,,,,,'."\r\n"
            .'SI0016/10/2022,13/08/2022,2022-08-0122-MP,"112.01.01 - PIUTANG USAHA - [Sales, BPK. A.TAUFIK]",2276784,0,,KE-JOHANSEN,,,BPP'."\r\n"
            .',13/08/2022,2022-08-0122-MP,"410.01.02 - PENJUALAN KREDIT - [BIAYA HANDLING]",0,2069804,PPN-K11(EXC),KE-JOHANSEN,,,'."\r\n"
            .',13/08/2022,2022-08-0122-MP,"219.01.01 - HUTANG PPN - [Tax : BIAYA HANDLING]",0,206980,,KE-JOHANSEN,,,'."\r\n"
            .'Total For :[Sales Journal],,,,2276784,2276784,,,,,'."\r\n";

        $batch = $this->makeBatch($csv, 'sales_invoice');
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status);
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame(0, $batch->failed_rows);
        $this->assertSame(0, $batch->preview_summary['needs_review_rows']);

        $entry = JournalEntry::query()->where('source_document_number', 'SI0016/10/2022')->with('lines')->firstOrFail();
        $this->assertSame('submitted', $entry->status->value);
        $this->assertEquals(2276784, (float) $entry->total_debit);
        $this->assertEquals(2276784, (float) $entry->total_credit);
        $this->assertCount(3, $entry->lines);

        $branchLine = $entry->lines->firstWhere('debit', '>', 0);
        $this->assertNotNull($branchLine->branch_id);
        $this->assertSame('BPP', Branch::query()->find($branchLine->branch_id)->code);

        $taxLine = $entry->lines->first(fn ($l) => str_contains((string) $l->description, 'PPN-K11'));
        $this->assertNotNull($taxLine, 'Tax Code should be folded into the line description.');
    }

    public function test_unmatched_account_code_is_redirected_to_suspense_and_flagged_for_review(): void
    {
        $csv = self::PREAMBLE.self::HEADER
            .'Purchase Journal,,,,,,,,,,'."\r\n"
            .'PI-0001,01/07/2022,REF-1,"210.01.01 - HUTANG SUPPLIER - [Purchases, PT XYZ]",0,100000,,,,,'."\r\n"
            .',01/07/2022,REF-1,"999.99.99 - UNKNOWN ACCOUNT - [Beli sesuatu]",100000,0,,,,,'."\r\n"
            .'Total For :[Purchase Journal],,,,100000,100000,,,,,'."\r\n";

        $batch = $this->makeBatch($csv, 'purchase_invoice');
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status);
        $this->assertSame(0, $batch->success_rows);
        $this->assertSame(1, $batch->preview_summary['needs_review_rows']);
        $this->assertStringContainsString('999.99.99', $batch->preview_summary['vouchers'][0]['reason']);

        $entry = JournalEntry::query()->where('source_document_number', 'PI-0001')->with('lines.chartOfAccount')->firstOrFail();
        $suspenseLine = $entry->lines->first(fn ($l) => $l->chartOfAccount->code === SalesPurchaseJournalImportService::SUSPENSE_ACCOUNT_CODE);
        $this->assertNotNull($suspenseLine);
        $this->assertEquals(100000, (float) $suspenseLine->debit);
    }

    public function test_unbalanced_group_fails_without_blocking_the_rest_of_the_batch(): void
    {
        $csv = self::PREAMBLE.self::HEADER
            .'Purchase Journal,,,,,,,,,,'."\r\n"
            // Deliberately unbalanced group.
            .'PI-BAD,01/07/2022,,"210.01.01 - HUTANG SUPPLIER - [Purchases, PT XYZ]",0,100000,,,,,'."\r\n"
            .',01/07/2022,,"510.01.02 - PEMBELIAN - [Beli barang]",90000,0,,,,,'."\r\n"
            // A good, balanced group right after it.
            .'PI-GOOD,02/07/2022,,"210.01.01 - HUTANG SUPPLIER - [Purchases, PT XYZ]",0,50000,,,,,'."\r\n"
            .',02/07/2022,,"510.01.02 - PEMBELIAN - [Beli barang]",50000,0,,,,,'."\r\n"
            .'Total For :[Purchase Journal],,,,140000,150000,,,,,'."\r\n";

        $batch = $this->makeBatch($csv, 'purchase_invoice');
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status);
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame(1, $batch->failed_rows);
        $this->assertSame(0, JournalEntry::query()->where('source_document_number', 'PI-BAD')->count());
        $this->assertSame(1, JournalEntry::query()->where('source_document_number', 'PI-GOOD')->count());

        $failed = collect($batch->preview_summary['vouchers'])->firstWhere('document_number', 'PI-BAD');
        $this->assertSame('failed', $failed['status']);
        $this->assertStringContainsString('tidak sama dengan Credit', $failed['reason']);
    }

    public function test_checksum_mismatch_is_a_warning_not_a_failure(): void
    {
        $csv = self::PREAMBLE.self::HEADER
            .'Purchase Journal,,,,,,,,,,'."\r\n"
            .'PI-0001,01/07/2022,,"210.01.01 - HUTANG SUPPLIER - [Purchases, PT XYZ]",0,100000,,,,,'."\r\n"
            .',01/07/2022,,"510.01.02 - PEMBELIAN - [Beli barang]",100000,0,,,,,'."\r\n"
            // Deliberately wrong trailer total (real legacy files have small trailer mismatches too).
            .'Total For :[Purchase Journal],,,,999999,999999,,,,,'."\r\n";

        $batch = $this->makeBatch($csv, 'purchase_invoice');
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status);
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame(1, JournalEntry::query()->where('source_document_number', 'PI-0001')->count());

        $checksum = collect($batch->preview_summary['vouchers'])->firstWhere('document_number', 'CHECKSUM');
        $this->assertNotNull($checksum);
        $this->assertStringContainsString('tidak cocok dengan baris Total', $checksum['reason']);
    }

    public function test_duplicate_document_number_is_skipped_by_default_but_can_be_created_anyway(): void
    {
        $csv = self::PREAMBLE.self::HEADER
            .'Purchase Journal,,,,,,,,,,'."\r\n"
            .'PI-0001,01/07/2022,,"210.01.01 - HUTANG SUPPLIER - [Purchases, PT XYZ]",0,100000,,,,,'."\r\n"
            .',01/07/2022,,"510.01.02 - PEMBELIAN - [Beli barang]",100000,0,,,,,'."\r\n"
            .'Total For :[Purchase Journal],,,,100000,100000,,,,,'."\r\n";

        $skipBatch = $this->makeBatch($csv, 'purchase_invoice', 'skip');
        $this->service->import($skipBatch);
        $this->assertSame(1, JournalEntry::query()->where('source_document_number', 'PI-0001')->count());

        // Re-importing the same file with the default "skip" policy leaves the existing entry alone.
        $again = $this->makeBatch($csv, 'purchase_invoice', 'skip');
        $this->service->import($again);
        $again->refresh();
        $this->assertSame(1, JournalEntry::query()->where('source_document_number', 'PI-0001')->count());
        $voucher = collect($again->preview_summary['vouchers'])->firstWhere('document_number', 'PI-0001');
        $this->assertSame('needs_review', $voucher['status']);
        $this->assertStringContainsString('sudah pernah diimpor', $voucher['reason']);

        // "create_anyway" posts a second entry for the same legacy document number.
        $createAnyway = $this->makeBatch($csv, 'purchase_invoice', 'create_anyway');
        $this->service->import($createAnyway);
        $this->assertSame(2, JournalEntry::query()->where('source_document_number', 'PI-0001')->count());
    }

    /**
     * A trimmed slice (first ~60 real data rows, trailer recomputed over just that slice) of the
     * actual attached sample files (xlsJournalList (2)/(3).xlsx) — real headers/labels/particulars/
     * account codes exactly as exported by the legacy system, not synthesized. Running the full
     * ~170k/~25k-row files isn't practical for every CI run, but this catches real-format quirks a
     * hand-written fixture would miss. The full files were run manually end-to-end via peek() +
     * import() against a throwaway sqlite DB during implementation to confirm they parse cleanly.
     */
    public function test_real_sample_file_slices_import_cleanly(): void
    {
        foreach ([
            ['219.01.02', 'HUTANG PPN', 'liability'],
            ['411.01.01', 'JASA ANGKUTAN', 'revenue'],
            ['610.02.07', 'BIAYA LAIN LAIN', 'expense'],
            ['610.04.02', 'BIAYA PEMELIHARAAN KENDARAAN', 'expense'],
            ['113.02.01', 'PPN MASUKAN', 'asset'],
        ] as [$code, $name, $type]) {
            ChartOfAccount::query()->create(['code' => $code, 'name' => $name, 'account_type' => $type, 'is_active' => true]);
        }

        foreach ([
            ['sales_journal_invoice_sample.csv', 'sales_invoice'],
            ['purchase_journal_invoice_sample.csv', 'purchase_invoice'],
        ] as [$fixture, $view]) {
            $csv = file_get_contents(__DIR__.'/../Fixtures/'.$fixture);
            $batch = $this->makeBatch($csv, $view);
            $this->service->import($batch);
            $batch->refresh();

            $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, "{$fixture}: {$batch->failure_reason}");
            $this->assertGreaterThan(0, $batch->success_rows, "{$fixture} should post at least one entry.");
            $this->assertSame(0, $batch->failed_rows, "{$fixture} should not fail any group.");

            $checksum = collect($batch->preview_summary['vouchers'])->firstWhere('document_number', 'CHECKSUM');
            $this->assertNull($checksum, "{$fixture}: trailer was recomputed over the slice, so it must match exactly.");
        }
    }
}
