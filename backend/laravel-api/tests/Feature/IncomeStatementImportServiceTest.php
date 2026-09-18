<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Models\ChartOfAccount;
use App\Models\ImportBatch;
use App\Models\JournalEntry;
use App\Services\Import\IncomeStatementImportService;
use App\Services\Import\PrintLedgerImportService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Exercises IncomeStatementImportService end-to-end. Income Statement is a read-only presentation
 * layer over GeneralLedgerService's period movement (ProfitLossService) — this import can only
 * affect it by posting a real Journal Entry, same trick as TrialBalanceImportService.
 */
class IncomeStatementImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected IncomeStatementImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DocumentEngineSeeder::class);
        $this->service = app(IncomeStatementImportService::class);
    }

    private function makeBatch(string $csv, string $duplicatePolicy = 'skip'): ImportBatch
    {
        $path = 'imports/test.csv';
        Storage::disk('local')->put($path, $csv);

        return ImportBatch::query()->create([
            'module' => 'profit-loss',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => 'test.csv',
            'disk' => 'local',
            'file_path' => $path,
            'write_mode' => $duplicatePolicy,
        ]);
    }

    // Row 4's blank separator must have commas (",,,") — a truly empty CSV line parses via
    // fgetcsv() as a single-null-field row, which ImportFileReader::readRawCsv() drops entirely
    // as noise, shifting every later row index by one (a real .xlsx file's blank row 4 has no such
    // gap — Excel::toCollection() preserves it as an all-null row, matching the real attached
    // sample). Found by the section-checksum test below silently losing its section context.
    private const PREAMBLE = "INCOME STATEMENT\r\nPT. KALINDO ETAM\r\n01/01/2022 - 31/12/2025,,,17/09/2026 10:09:44\r\n,,,\r\n,,Year-To-Date (RP),%\r\n";

    public function test_positive_and_negative_values_post_to_the_correct_side_by_normal_balance(): void
    {
        // Exact-code matches throughout — this test is about sign handling, not fuzzy matching
        // (that's covered separately below).
        // Revenue is credit-normal — positive posts to its normal side (credit).
        ChartOfAccount::query()->create(['code' => '410.01.02', 'name' => 'PENJUALAN KREDIT', 'account_type' => 'revenue', 'is_active' => true]);
        // Sales Returns is expense/debit-normal — a NEGATIVE value posts to the opposite side (credit) per the ticket's literal rule.
        ChartOfAccount::query()->create(['code' => '410.01.03', 'name' => 'RETUR PENJUALAN', 'account_type' => 'expense', 'is_active' => true]);
        // Cost of Sales — expense/debit-normal, positive posts to its normal side (debit).
        ChartOfAccount::query()->create(['code' => '510.01.02', 'name' => 'PEMBELIAN', 'account_type' => 'expense', 'is_active' => true]);

        $csv = self::PREAMBLE
            .'Income,,,'."\r\n"
            .'410.01.02,PENJUALAN KREDIT,150000,'."\r\n"
            .'410.01.03,RETUR PENJUALAN,-6000,'."\r\n"
            .'Total Income,,144000,'."\r\n"
            .',,,'."\r\n"
            .'Cost of Sales,,,'."\r\n"
            .'510.01.02,PEMBELIAN,100000,'."\r\n"
            .'Total Cost of Sales,,100000,'."\r\n"
            .',,,'."\r\n"
            .'GROSS PROFIT/(LOSS),,44000,'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, (string) $batch->failure_reason);
        $this->assertSame(3, $batch->success_rows);
        $this->assertSame(0, $batch->failed_rows);

        $entry = JournalEntry::query()->where('source_document_number', 'IMPORT-IS-01/01/2022-31/12/2025')->with('lines.chartOfAccount')->firstOrFail();
        $this->assertSame('submitted', $entry->status->value);

        $revenueLine = $entry->lines->first(fn ($l) => $l->chartOfAccount->code === '410.01.02');
        $this->assertEquals(150000, (float) $revenueLine->credit, 'positive revenue posts to its own normal (credit) side');
        $this->assertEquals(0, (float) $revenueLine->debit);

        $returnsLine = $entry->lines->first(fn ($l) => $l->chartOfAccount->code === '410.01.03');
        $this->assertEquals(6000, (float) $returnsLine->credit, 'negative value on a debit-normal account posts to the opposite (credit) side');
        $this->assertEquals(0, (float) $returnsLine->debit);

        $cogsLine = $entry->lines->first(fn ($l) => $l->chartOfAccount->code === '510.01.02');
        $this->assertEquals(100000, (float) $cogsLine->debit, 'positive expense posts to its own normal (debit) side');
    }

    public function test_zero_value_row_and_non_data_rows_are_never_posted(): void
    {
        ChartOfAccount::query()->create(['code' => '4000', 'name' => 'PENJUALAN KREDIT', 'account_type' => 'revenue', 'is_active' => true]);
        ChartOfAccount::query()->create(['code' => '5000', 'name' => 'SALDO AWAL', 'account_type' => 'expense', 'is_active' => true]);

        $csv = self::PREAMBLE
            .'Income,,,'."\r\n"
            .'410.01.02,PENJUALAN KREDIT,100000,'."\r\n"
            .'Total Income,,100000,'."\r\n"
            .',,,'."\r\n"
            .'Cost of Sales,,,'."\r\n"
            .'510.01.01,SALDO AWAL,0,'."\r\n" // legitimate zero-value row — never posted
            .'Total Cost of Sales,,0,'."\r\n"
            .',,,'."\r\n"
            .'GROSS PROFIT/(LOSS),,100000,'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, (string) $batch->failure_reason);

        $entry = JournalEntry::query()->where('source_document_number', 'IMPORT-IS-01/01/2022-31/12/2025')->with('lines.chartOfAccount')->firstOrFail();
        $this->assertNull($entry->lines->first(fn ($l) => $l->chartOfAccount->code === '5000'), 'a zero-value row must never become a Journal Entry line');
        // "Total Income"/"Total Cost of Sales"/"GROSS PROFIT/(LOSS)" all have real numbers in
        // column C but must never themselves become postable lines.
        $this->assertCount(1, $entry->lines->filter(fn ($l) => $l->chartOfAccount->code !== PrintLedgerImportService::SUSPENSE_ACCOUNT_CODE));
    }

    public function test_unmatched_account_is_excluded_and_suspense_plug_keeps_it_balanced(): void
    {
        ChartOfAccount::query()->create(['code' => '410.01.02', 'name' => 'PENJUALAN KREDIT', 'account_type' => 'revenue', 'is_active' => true]);

        $csv = self::PREAMBLE
            .'Income,,,'."\r\n"
            .'410.01.02,PENJUALAN KREDIT,100000,'."\r\n"
            .'999.99.99,AKUN MISTERIUS TIDAK DIKENAL,50000,'."\r\n"
            .'Total Income,,150000,'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status);
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame(1, $batch->failed_rows);

        $entry = JournalEntry::query()->where('source_document_number', 'IMPORT-IS-01/01/2022-31/12/2025')->with('lines.chartOfAccount')->firstOrFail();
        $this->assertEquals(100000, (float) $entry->total_debit);
        $this->assertEquals(100000, (float) $entry->total_credit);

        // The unmatched row (50,000) is excluded entirely — with only the revenue credit line
        // (100,000) left, the suspense plug must cover the whole debit side to balance, not just
        // the unmatched row's own amount.
        $suspenseLine = $entry->lines->first(fn ($l) => $l->chartOfAccount->code === PrintLedgerImportService::SUSPENSE_ACCOUNT_CODE);
        $this->assertNotNull($suspenseLine);
        $this->assertEquals(100000, (float) $suspenseLine->debit);

        // The per-section checksum is a parse-level sanity check (file's own "Total Income" vs.
        // the sum of every listed data row, matched or not) — it's unaffected by account-matching,
        // so it correctly stays quiet here: the file's own arithmetic (100,000 + 50,000 = 150,000)
        // genuinely adds up, even though one of those two rows was excluded at posting time.
        $checksum = collect($batch->preview_summary['vouchers'])->first(fn ($v) => str_contains((string) $v['document_number'], 'CHECKSUM'));
        $this->assertNull($checksum);
    }

    public function test_section_checksum_mismatch_is_a_warning_not_a_failure(): void
    {
        ChartOfAccount::query()->create(['code' => '410.01.02', 'name' => 'PENJUALAN KREDIT', 'account_type' => 'revenue', 'is_active' => true]);

        $csv = self::PREAMBLE
            .'Income,,,'."\r\n"
            .'410.01.02,PENJUALAN KREDIT,100000,'."\r\n"
            // Deliberately wrong stated total for this section — the file's own arithmetic doesn't add up.
            .'Total Income,,999999,'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status);
        $this->assertSame(1, $batch->success_rows);

        $checksum = collect($batch->preview_summary['vouchers'])->first(fn ($v) => str_contains((string) $v['document_number'], 'CHECKSUM'));
        $this->assertNotNull($checksum);
        $this->assertStringContainsString('Income', $checksum['reason']);
    }

    public function test_duplicate_period_is_skipped_by_default_but_can_be_created_anyway(): void
    {
        ChartOfAccount::query()->create(['code' => '4000', 'name' => 'PENJUALAN KREDIT', 'account_type' => 'revenue', 'is_active' => true]);

        $csv = self::PREAMBLE
            .'Income,,,'."\r\n"
            .'410.01.02,PENJUALAN KREDIT,100000,'."\r\n"
            .'Total Income,,100000,'."\r\n";

        $first = $this->makeBatch($csv, 'skip');
        $this->service->import($first);
        $this->assertSame(1, JournalEntry::query()->where('source_document_number', 'IMPORT-IS-01/01/2022-31/12/2025')->count());

        $again = $this->makeBatch($csv, 'skip');
        $this->service->import($again);
        $again->refresh();
        $this->assertSame(1, JournalEntry::query()->where('source_document_number', 'IMPORT-IS-01/01/2022-31/12/2025')->count());
        $this->assertStringContainsString('sudah pernah diimpor', $again->preview_summary['vouchers'][0]['reason']);

        $createAnyway = $this->makeBatch($csv, 'create_anyway');
        $this->service->import($createAnyway);
        $this->assertSame(2, JournalEntry::query()->where('source_document_number', 'IMPORT-IS-01/01/2022-31/12/2025')->count());
    }

    /**
     * The real attached sample file (xlsincomestatement.xlsx, 89 rows, project root) — inspected
     * by hand during planning; it's what corrected the ticket's own column-classification prose
     * (a data row is identified by a dotted account code in column A, not "column C has a
     * number" — subtotal and summary rows have that too).
     */
    public function test_real_sample_file_parses_and_posts_cleanly(): void
    {
        $realFile = dirname(__DIR__, 4).'/xlsincomestatement.xlsx';

        if (! file_exists($realFile)) {
            $this->markTestSkipped('Real xlsincomestatement.xlsx sample not present in the project root.');
        }

        foreach ([
            ['410.01.02', 'PENJUALAN KREDIT', 'revenue'],
            ['410.01.03', 'RETUR PENJUALAN', 'revenue'],
            ['411.01.01', 'JASA ANGKUTAN', 'revenue'],
            ['510.01.02', 'PEMBELIAN', 'expense'],
            ['610.01.01', 'BIAYA GAJI', 'expense'],
            ['610.02.07', 'BIAYA BBM', 'expense'],
            ['710.02.09', 'PENDAPATAN LAIN-LAIN', 'revenue'],
        ] as [$code, $name, $type]) {
            ChartOfAccount::query()->create(['code' => $code, 'name' => $name, 'account_type' => $type, 'is_active' => true]);
        }

        $path = 'imports/real-income-statement.xlsx';
        Storage::disk('local')->put($path, file_get_contents($realFile));

        $batch = ImportBatch::query()->create([
            'module' => 'profit-loss',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => 'xlsincomestatement.xlsx',
            'disk' => 'local',
            'file_path' => $path,
            'write_mode' => 'skip',
        ]);

        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, (string) $batch->failure_reason);
        $this->assertGreaterThan(0, $batch->success_rows);

        $entry = JournalEntry::query()->where('source_document_number', 'IMPORT-IS-01/01/2022-31/12/2025')->with('lines.chartOfAccount')->first();
        $this->assertNotNull($entry);
        $this->assertSame('submitted', $entry->status->value);
        $this->assertSame('2025-12-31', $entry->posting_date->toDateString());

        // 410.01.03 RETUR PENJUALAN is -6,237,838.51 in the real file on a revenue (credit-normal)
        // account — per the confirmed literal rule, a negative value posts to the opposite
        // (debit) side.
        $returnsLine = $entry->lines->first(fn ($l) => $l->chartOfAccount->code === '410.01.03');
        $this->assertNotNull($returnsLine);
        $this->assertEquals(6237838.51, (float) $returnsLine->debit);
        $this->assertEquals(0, (float) $returnsLine->credit);
    }
}
