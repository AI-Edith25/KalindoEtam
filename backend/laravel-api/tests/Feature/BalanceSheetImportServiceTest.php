<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Models\ChartOfAccount;
use App\Models\ImportBatch;
use App\Models\JournalEntry;
use App\Services\Import\BalanceSheetImportService;
use App\Services\Import\PrintLedgerImportService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Exercises BalanceSheetImportService end-to-end. Balance Sheet is a read-only presentation layer
 * over GeneralLedgerService's cumulative ending_balance plus ProfitLossService's Current Year
 * Profit — this import can only affect it by posting a real Journal Entry, same trick as
 * TrialBalanceImportService/IncomeStatementImportService.
 */
class BalanceSheetImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected BalanceSheetImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DocumentEngineSeeder::class);
        $this->service = app(BalanceSheetImportService::class);
    }

    private function makeBatch(string $csv, string $duplicatePolicy = 'skip'): ImportBatch
    {
        $path = 'imports/test.csv';
        Storage::disk('local')->put($path, $csv);

        return ImportBatch::query()->create([
            'module' => 'balance-sheet',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => 'test.csv',
            'disk' => 'local',
            'file_path' => $path,
            'write_mode' => $duplicatePolicy,
        ]);
    }

    // Row 4's blank separator needs commas — see IncomeStatementImportServiceTest's own note: a
    // truly empty CSV line is dropped entirely by ImportFileReader::readRawCsv(), shifting every
    // later row index by one versus a real .xlsx file's preserved blank row.
    private const PREAMBLE = "BALANCE SHEET\r\nPT. KALINDO ETAM\r\n01/01/2022 - 31/12/2025,,,,17/09/2026 10:09:44\r\n,,,,\r\n,,Year-To-Date (RP),%,\r\n";

    public function test_level3_accounts_post_by_normal_side_spanning_multiple_subgroups_into_one_total(): void
    {
        ChartOfAccount::query()->create(['code' => '121.03.01', 'name' => 'KENDARAAN RODA 2 & 4', 'account_type' => 'asset', 'is_active' => true]);
        // Accumulated depreciation — a contra-asset, still ASSET type (debit-normal) in this
        // schema; its negative YTD value must post to the opposite (credit) side per the ticket.
        ChartOfAccount::query()->create(['code' => '121.03.02', 'name' => 'AKUM. PENYUSUTAN KENDARAAN', 'account_type' => 'asset', 'is_active' => true]);
        ChartOfAccount::query()->create(['code' => '121.04.01', 'name' => 'INVENTARIS KANTOR', 'account_type' => 'asset', 'is_active' => true]);
        ChartOfAccount::query()->create(['code' => '350.01.01', 'name' => 'MODAL', 'account_type' => 'equity', 'is_active' => true]);

        $csv = self::PREAMBLE
            .'PROPERTY PLANT & EQUIPMENT,,,,'."\r\n"
            .'121,AKTIVA TETAP,,,'."\r\n"
            .'  121.03,KENDARAAN,,,'."\r\n"
            .'    121.03.01,KENDARAAN RODA 2 & 4,100000,,'."\r\n"
            .'    121.03.02,AKUM. PENYUSUTAN KENDARAAN,-40000,,'."\r\n"
            .'  121.04,INVENTARIS KANTOR,,,'."\r\n"
            .'    121.04.01,INVENTARIS KANTOR,10000,,'."\r\n"
            // One Total spans BOTH sub-groups (121.03 and 121.04) — mirrors the real file exactly.
            .'TOTAL PROPERTY PLANT & EQUIPMENT,,70000,,'."\r\n"
            .',,,,'."\r\n"
            .'FINANCE BY,,,,'."\r\n"
            .'350,MODAL SAHAM,,,'."\r\n"
            .'  350.01.01,MODAL,70000,,'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, (string) $batch->failure_reason);
        $this->assertSame(4, $batch->success_rows);
        $this->assertSame(0, $batch->failed_rows);

        $entry = JournalEntry::query()->where('source_document_number', 'IMPORT-BS-01/01/2022-31/12/2025')->with('lines.chartOfAccount')->firstOrFail();
        $this->assertSame('submitted', $entry->status->value);
        $this->assertSame('2025-12-31', $entry->posting_date->toDateString());

        $vehicleLine = $entry->lines->first(fn ($l) => $l->chartOfAccount->code === '121.03.01');
        $this->assertEquals(100000, (float) $vehicleLine->debit, 'positive asset posts to its normal (debit) side');

        $depreciationLine = $entry->lines->first(fn ($l) => $l->chartOfAccount->code === '121.03.02');
        $this->assertEquals(40000, (float) $depreciationLine->credit, 'negative value on a debit-normal account posts to the opposite (credit) side');
        $this->assertEquals(0, (float) $depreciationLine->debit);

        // No checksum warning — the file's own "TOTAL PROPERTY PLANT & EQUIPMENT" (70,000) matches
        // 100,000 - 40,000 + 10,000 = 70,000, spanning both sub-groups since the last reset.
        $checksum = collect($batch->preview_summary['vouchers'])->first(fn ($v) => str_contains((string) $v['document_number'], 'CHECKSUM'));
        $this->assertNull($checksum);
    }

    public function test_group_subgroup_section_and_blank_summary_rows_are_never_posted(): void
    {
        ChartOfAccount::query()->create(['code' => '121.03.01', 'name' => 'KENDARAAN RODA 2 & 4', 'account_type' => 'asset', 'is_active' => true]);

        $csv = self::PREAMBLE
            .'PROPERTY PLANT & EQUIPMENT,,,,'."\r\n" // Level 0 section — pure text, no dots
            .'121,AKTIVA TETAP,,,'."\r\n"            // Level 1 group — pure integer
            .'  121.03,KENDARAAN,,,'."\r\n"          // Level 2 sub-group — exactly 1 dot
            .'    121.03.01,KENDARAAN RODA 2 & 4,100000,,'."\r\n" // Level 3 — the only real data row
            .'TOTAL PROPERTY PLANT & EQUIPMENT,,100000,,'."\r\n"
            .',,50000,,'."\r\n" // blank-A/B derived summary line (e.g. "Net Assets") — has a value, still not data
            .'  CURRENT ADJUSMENT TO RETAINED EARNING A/C,,-1000,,'."\r\n"; // text label, no dots, still not data

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, (string) $batch->failure_reason);
        $this->assertSame(1, $batch->success_rows, 'only the one real Level-3 account row should be counted');

        $entry = JournalEntry::query()->where('source_document_number', 'IMPORT-BS-01/01/2022-31/12/2025')->with('lines')->firstOrFail();
        // One real account line + one suspense plug (nothing else matched to offset the debit) = 2 lines.
        $this->assertCount(2, $entry->lines);
    }

    public function test_unmatched_account_is_excluded_and_suspense_plug_keeps_it_balanced(): void
    {
        ChartOfAccount::query()->create(['code' => '121.03.01', 'name' => 'KENDARAAN RODA 2 & 4', 'account_type' => 'asset', 'is_active' => true]);

        $csv = self::PREAMBLE
            .'PROPERTY PLANT & EQUIPMENT,,,,'."\r\n"
            .'121,AKTIVA TETAP,,,'."\r\n"
            .'  121.03,KENDARAAN,,,'."\r\n"
            .'    121.03.01,KENDARAAN RODA 2 & 4,100000,,'."\r\n"
            .'    999.99.99,AKUN MISTERIUS TIDAK DIKENAL,50000,,'."\r\n"
            .'TOTAL PROPERTY PLANT & EQUIPMENT,,150000,,'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status);
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame(1, $batch->failed_rows);

        $entry = JournalEntry::query()->where('source_document_number', 'IMPORT-BS-01/01/2022-31/12/2025')->with('lines.chartOfAccount')->firstOrFail();
        // The unmatched row (50,000) is excluded entirely — with only the 100,000 debit line
        // left, the suspense plug must cover the whole credit side to balance, not just the
        // unmatched row's own amount.
        $suspenseLine = $entry->lines->first(fn ($l) => $l->chartOfAccount->code === PrintLedgerImportService::SUSPENSE_ACCOUNT_CODE);
        $this->assertNotNull($suspenseLine);
        $this->assertEquals(100000, (float) $suspenseLine->credit);
    }

    public function test_duplicate_period_is_skipped_by_default_but_can_be_created_anyway(): void
    {
        ChartOfAccount::query()->create(['code' => '121.03.01', 'name' => 'KENDARAAN RODA 2 & 4', 'account_type' => 'asset', 'is_active' => true]);

        $csv = self::PREAMBLE
            .'121,AKTIVA TETAP,,,'."\r\n"
            .'    121.03.01,KENDARAAN RODA 2 & 4,100000,,'."\r\n";

        $first = $this->makeBatch($csv, 'skip');
        $this->service->import($first);
        $this->assertSame(1, JournalEntry::query()->where('source_document_number', 'IMPORT-BS-01/01/2022-31/12/2025')->count());

        $again = $this->makeBatch($csv, 'skip');
        $this->service->import($again);
        $again->refresh();
        $this->assertSame(1, JournalEntry::query()->where('source_document_number', 'IMPORT-BS-01/01/2022-31/12/2025')->count());
        $this->assertStringContainsString('sudah pernah diimpor', $again->preview_summary['vouchers'][0]['reason']);

        $createAnyway = $this->makeBatch($csv, 'create_anyway');
        $this->service->import($createAnyway);
        $this->assertSame(2, JournalEntry::query()->where('source_document_number', 'IMPORT-BS-01/01/2022-31/12/2025')->count());
    }

    /**
     * The real attached sample file (xlsbalancesheet_maintainstockvalue.xlsx, 93 rows, project
     * root) — inspected by hand during planning; it's what revealed that leading-space
     * indentation is unreliable (row 85, "350.01.01", a real Level-3 account, is indented exactly
     * like a Level-2 sub-group) and confirmed one "TOTAL X" line can close out multiple sub-groups.
     */
    public function test_real_sample_file_parses_and_posts_cleanly(): void
    {
        $realFile = dirname(__DIR__, 4).'/xlsbalancesheet_maintainstockvalue.xlsx';

        if (! file_exists($realFile)) {
            $this->markTestSkipped('Real xlsbalancesheet_maintainstockvalue.xlsx sample not present in the project root.');
        }

        foreach ([
            ['121.03.01', 'KENDARAAN RODA 2 & 4', 'asset'],
            ['121.03.02', 'AKUM. PENYUSUTAN KENDARAAN RODA 2 & 4', 'asset'],
            ['112.01.01', 'PIUTANG USAHA', 'asset'],
            ['210.01.01', 'HUTANG SUPPLIER', 'liability'],
            ['219.01.01', 'HUTANG PPN', 'liability'],
            ['350.01.01', 'MODAL', 'equity'],
            ['310.01.01', 'LABA RUGI', 'equity'],
        ] as [$code, $name, $type]) {
            ChartOfAccount::query()->create(['code' => $code, 'name' => $name, 'account_type' => $type, 'is_active' => true]);
        }

        $path = 'imports/real-balance-sheet.xlsx';
        Storage::disk('local')->put($path, file_get_contents($realFile));

        $batch = ImportBatch::query()->create([
            'module' => 'balance-sheet',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => 'xlsbalancesheet_maintainstockvalue.xlsx',
            'disk' => 'local',
            'file_path' => $path,
            'write_mode' => 'skip',
        ]);

        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, (string) $batch->failure_reason);
        $this->assertGreaterThan(0, $batch->success_rows);

        $entry = JournalEntry::query()->where('source_document_number', 'IMPORT-BS-01/01/2022-31/12/2025')->with('lines.chartOfAccount')->first();
        $this->assertNotNull($entry);
        $this->assertSame('submitted', $entry->status->value);
        $this->assertSame('2025-12-31', $entry->posting_date->toDateString());

        // 121.03.02 (accumulated depreciation) is -27,544,102,926.04 in the real file on an
        // ASSET (debit-normal) account — negative posts to the opposite (credit) side.
        $depreciationLine = $entry->lines->first(fn ($l) => $l->chartOfAccount->code === '121.03.02');
        $this->assertNotNull($depreciationLine);
        $this->assertEquals(27544102926.04, (float) $depreciationLine->credit);
        $this->assertEquals(0, (float) $depreciationLine->debit);

        // 350.01.01 (MODAL) is a real Level-3 account despite being indented like a Level-2
        // sub-group in the real file — must still be posted, not skipped.
        $modalLine = $entry->lines->first(fn ($l) => $l->chartOfAccount->code === '350.01.01');
        $this->assertNotNull($modalLine, 'MODAL must be posted despite its sub-group-like indentation');
    }
}
