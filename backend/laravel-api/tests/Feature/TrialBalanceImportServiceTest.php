<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Models\ChartOfAccount;
use App\Models\ImportBatch;
use App\Models\JournalEntry;
use App\Services\Import\PrintLedgerImportService;
use App\Services\Import\TrialBalanceImportService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Exercises TrialBalanceImportService end-to-end. Trial Balance is a read-only presentation layer
 * over GeneralLedgerService::listAccounts() (docs/TRIAL_BALANCE_DESIGN.md) — this import can only
 * affect it by posting a real Journal Entry (same trick as PrintLedgerImportService), never by
 * writing to a Trial Balance table directly (there isn't one).
 */
class TrialBalanceImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TrialBalanceImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DocumentEngineSeeder::class);
        $this->service = app(TrialBalanceImportService::class);
    }

    private function makeBatch(string $csv, string $duplicatePolicy = 'skip'): ImportBatch
    {
        $path = 'imports/test.csv';
        Storage::disk('local')->put($path, $csv);

        return ImportBatch::query()->create([
            'module' => 'trial-balance',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => 'test.csv',
            'disk' => 'local',
            'file_path' => $path,
            'write_mode' => $duplicatePolicy,
        ]);
    }

    private const PREAMBLE = "TRIAL BALANCE\r\nPT. KALINDO ETAM\r\n01/01/2022 - 31/12/2025,,,,17/09/2026 10:09:44\r\n\r\n";

    private const HEADER = "ACCOUNT # ,DESCRIPTION,YEAR TO DATE [DR] (RP),YEAR TO DATE [CR] (RP)\r\n";

    public function test_exact_and_fuzzy_matches_post_a_balanced_combined_journal_entry(): void
    {
        ChartOfAccount::query()->create(['code' => '1100', 'name' => 'KAS BESAR SAMARINDA', 'account_type' => 'asset', 'is_active' => true]);
        ChartOfAccount::query()->create(['code' => '2000', 'name' => 'HUTANG SUPPLIER - BARU', 'account_type' => 'liability', 'is_active' => true]);

        $csv = self::PREAMBLE.self::HEADER
            // Exact code match — old code happens to equal the new one this time.
            .'1100,KAS BESAR SAMARINDA,500000,0'."\r\n"
            // Old code doesn't exist in the new chart at all — must fall back to fuzzy name match.
            .'210.01.01,HUTANG SUPPLIER,0,500000'."\r\n"
            .',,1000000,500000'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status);
        $this->assertSame(1, $batch->success_rows, 'one exact match');

        $entry = JournalEntry::query()->where('source_document_number', 'IMPORT-TB-01/01/2022-31/12/2025')->with('lines.chartOfAccount')->firstOrFail();
        $this->assertSame('submitted', $entry->status->value);
        $this->assertSame('2025-12-31', $entry->posting_date->toDateString());

        $fuzzyLine = $entry->lines->first(fn ($l) => $l->chartOfAccount->code === '2000');
        $this->assertNotNull($fuzzyLine, 'fuzzy match by name should have resolved to the new HUTANG SUPPLIER account');
        $this->assertEquals(500000, (float) $fuzzyLine->credit);

        $fuzzyReport = collect($batch->preview_summary['vouchers'])->first(fn ($v) => str_contains($v['document_number'], '210.01.01'));
        $this->assertNotNull($fuzzyReport);
        $this->assertStringContainsString('dicocokkan otomatis by nama', $fuzzyReport['reason']);
    }

    public function test_unmatched_account_is_excluded_and_suspense_plug_keeps_it_balanced(): void
    {
        ChartOfAccount::query()->create(['code' => '1100', 'name' => 'Kas Besar', 'account_type' => 'asset', 'is_active' => true]);

        $csv = self::PREAMBLE.self::HEADER
            .'1100,Kas Besar,500000,0'."\r\n"
            // Nothing in the new chart resembles this at all — must be excluded, not guessed.
            .'999.99.99,AKUN MISTERIUS TIDAK DIKENAL,0,500000'."\r\n"
            .',,500000,500000'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status);
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame(1, $batch->failed_rows, 'the unmatched account is excluded, counted separately from success');

        $entry = JournalEntry::query()->where('source_document_number', 'IMPORT-TB-01/01/2022-31/12/2025')->with('lines.chartOfAccount')->firstOrFail();
        $this->assertEquals(500000, (float) $entry->total_debit);
        $this->assertEquals(500000, (float) $entry->total_credit);

        $suspenseLine = $entry->lines->first(fn ($l) => $l->chartOfAccount->code === PrintLedgerImportService::SUSPENSE_ACCOUNT_CODE);
        $this->assertNotNull($suspenseLine, 'the excluded account\'s side of the balance must be plugged to keep the entry postable');
        $this->assertEquals(500000, (float) $suspenseLine->credit);

        $unmatchedReport = collect($batch->preview_summary['vouchers'])->first(fn ($v) => str_contains($v['document_number'], '999.99.99'));
        $this->assertStringContainsString('dilewati dari Journal Entry, bukan ditebak', $unmatchedReport['reason']);
    }

    public function test_balance_sheet_stock_section_is_not_skipped_and_total_row_scope_excludes_it(): void
    {
        ChartOfAccount::query()->create(['code' => '1100', 'name' => 'Kas Besar', 'account_type' => 'asset', 'is_active' => true]);
        ChartOfAccount::query()->create(['code' => '1300', 'name' => 'Persediaan Barang Dagang', 'account_type' => 'asset', 'is_active' => true]);
        ChartOfAccount::query()->create(['code' => '3000', 'name' => 'Modal', 'account_type' => 'equity', 'is_active' => true]);

        $csv = self::PREAMBLE.self::HEADER
            .'1100,Kas Besar,500000,0'."\r\n"
            .'114.01.01,PERSEDIAN BARANG DAGANG,0,0'."\r\n" // legitimate zero-balance row above the Total, same code repeats below with a real value
            .'350.01.01,MODAL,0,500000'."\r\n"
            .',,500000,500000'."\r\n" // Total row — scope is only what's above it
            ."\r\n"
            .'Balance Sheet Stock,,,'."\r\n"
            .'114.01.01,PERSEDIAN BARANG DAGANG,8390161012.65,0'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status);

        $entry = JournalEntry::query()->where('source_document_number', 'IMPORT-TB-01/01/2022-31/12/2025')->with('lines.chartOfAccount')->firstOrFail();
        $stockLine = $entry->lines->first(fn ($l) => $l->chartOfAccount->code === '1300');
        $this->assertNotNull($stockLine, 'the Balance Sheet Stock addendum row must not be skipped');
        $this->assertEquals(8390161012.65, (float) $stockLine->debit);

        $checksum = collect($batch->preview_summary['vouchers'])->first(fn ($v) => $v['document_number'] === 'CHECKSUM');
        $this->assertStringContainsString('Rp 500.000', $checksum['reason'], 'the file Total row only covers the rows above it, not the addendum');
    }

    public function test_out_of_balance_row_is_reported_but_never_blocks_posting(): void
    {
        ChartOfAccount::query()->create(['code' => '1100', 'name' => 'Kas Besar', 'account_type' => 'asset', 'is_active' => true]);
        ChartOfAccount::query()->create(['code' => '3000', 'name' => 'Modal', 'account_type' => 'equity', 'is_active' => true]);

        $csv = self::PREAMBLE.self::HEADER
            .'1100,Kas Besar,600000,0'."\r\n"
            .'350.01.01,MODAL,0,500000'."\r\n"
            .',,600000,500000'."\r\n"
            .',OUT OF BALANCE BY,100000,'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status);
        $oob = collect($batch->preview_summary['vouchers'])->first(fn ($v) => $v['document_number'] === 'OUT OF BALANCE');
        $this->assertStringContainsString('Rp 100.000', $oob['reason']);

        // The 100,000 gap between matched Kas Besar (600k) and Modal (500k) is the same OOB figure
        // — plugged into suspense so the entry still posts.
        $entry = JournalEntry::query()->where('source_document_number', 'IMPORT-TB-01/01/2022-31/12/2025')->with('lines.chartOfAccount')->firstOrFail();
        $suspenseLine = $entry->lines->first(fn ($l) => $l->chartOfAccount->code === PrintLedgerImportService::SUSPENSE_ACCOUNT_CODE);
        $this->assertNotNull($suspenseLine);
        $this->assertEquals(100000, (float) $suspenseLine->credit);
    }

    public function test_duplicate_period_is_skipped_by_default_but_can_be_created_anyway(): void
    {
        ChartOfAccount::query()->create(['code' => '1100', 'name' => 'Kas Besar', 'account_type' => 'asset', 'is_active' => true]);
        ChartOfAccount::query()->create(['code' => '3000', 'name' => 'Modal', 'account_type' => 'equity', 'is_active' => true]);

        $csv = self::PREAMBLE.self::HEADER
            .'1100,Kas Besar,500000,0'."\r\n"
            .'350.01.01,MODAL,0,500000'."\r\n"
            .',,500000,500000'."\r\n";

        $first = $this->makeBatch($csv, 'skip');
        $this->service->import($first);
        $this->assertSame(1, JournalEntry::query()->where('source_document_number', 'IMPORT-TB-01/01/2022-31/12/2025')->count());

        $again = $this->makeBatch($csv, 'skip');
        $this->service->import($again);
        $again->refresh();
        $this->assertSame(1, JournalEntry::query()->where('source_document_number', 'IMPORT-TB-01/01/2022-31/12/2025')->count());
        $this->assertStringContainsString('sudah pernah diimpor', $again->preview_summary['vouchers'][0]['reason']);

        $createAnyway = $this->makeBatch($csv, 'create_anyway');
        $this->service->import($createAnyway);
        $this->assertSame(2, JournalEntry::query()->where('source_document_number', 'IMPORT-TB-01/01/2022-31/12/2025')->count());
    }

    /**
     * The real attached sample file (xlsTrialBalance.xlsx, 105 rows, project root) — inspected by
     * hand during planning to confirm every structural quirk (Total row, OUT OF BALANCE BY,
     * Balance Sheet Stock addendum, a repeated account code with one zero-balance occurrence).
     * Seeds a handful of plausible new-format accounts so some rows exact/fuzzy-match and some
     * don't, exercising the real file's parsing without needing production's actual Chart of
     * Accounts.
     */
    public function test_real_sample_file_parses_and_posts_cleanly(): void
    {
        $realFile = dirname(__DIR__, 4).'/xlsTrialBalance.xlsx';

        if (! file_exists($realFile)) {
            $this->markTestSkipped('Real xlsTrialBalance.xlsx sample not present in the project root.');
        }

        ChartOfAccount::query()->create(['code' => '101.01.01', 'name' => 'KAS BESAR SAMARINDA', 'account_type' => 'asset', 'is_active' => true]);
        ChartOfAccount::query()->create(['code' => '1200', 'name' => 'PIUTANG USAHA', 'account_type' => 'asset', 'is_active' => true]);
        ChartOfAccount::query()->create(['code' => '2000', 'name' => 'HUTANG SUPPLIER', 'account_type' => 'liability', 'is_active' => true]);
        ChartOfAccount::query()->create(['code' => '1300', 'name' => 'PERSEDIAAN BARANG DAGANG', 'account_type' => 'asset', 'is_active' => true]);

        $path = 'imports/real-trial-balance.xlsx';
        Storage::disk('local')->put($path, file_get_contents($realFile));

        $batch = ImportBatch::query()->create([
            'module' => 'trial-balance',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => 'xlsTrialBalance.xlsx',
            'disk' => 'local',
            'file_path' => $path,
            'write_mode' => 'skip',
        ]);

        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, (string) $batch->failure_reason);
        $this->assertGreaterThan(0, $batch->success_rows);

        $entry = JournalEntry::query()->where('source_document_number', 'IMPORT-TB-01/01/2022-31/12/2025')->with('lines')->first();
        $this->assertNotNull($entry);
        $this->assertSame('submitted', $entry->status->value);
        $this->assertSame('2025-12-31', $entry->posting_date->toDateString());

        // 114.01.01 (PERSEDIAN BARANG DAGANG) appears twice in the real file: once above the Total
        // with a zero balance (must contribute nothing) and once in Balance Sheet Stock with the
        // real 8,390,161,012.65 figure (must be posted) — proves both quirks on real data at once.
        $stockLine = $entry->lines->firstWhere('debit', 8390161012.65);
        $this->assertNotNull($stockLine, 'Balance Sheet Stock addendum row must have posted');

        $oob = collect($batch->preview_summary['vouchers'])->first(fn ($v) => $v['document_number'] === 'OUT OF BALANCE');
        $this->assertNotNull($oob, 'the real file is genuinely out of balance by design (17,456,724.50)');
        $this->assertStringContainsString('17.456.725', $oob['reason']); // 17,456,724.5028 rounded to 0 decimals
    }
}
