<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Models\ChartOfAccount;
use App\Models\ImportBatch;
use App\Models\JournalEntry;
use App\Services\GeneralLedgerService;
use App\Services\Import\PrintLedgerImportService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Exercises the smart Print Ledger importer end-to-end against a CSV shaped
 * like the real "Print Ledger [Summary]" export (4 title rows incl. the
 * period range, the real (misspelled) header, one row per account). Real
 * sample file (xlsPrintLedger.xlsx, 110 real accounts, sum ~0) was used
 * during development to validate the header-detection/column-mapping and
 * numeric-parsing paths against actual production data — not committed here
 * as a fixture since it's the company's real trial balance.
 */
class GeneralLedgerImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PrintLedgerImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->service = app(PrintLedgerImportService::class);
    }

    private function makeBatch(string $csv): ImportBatch
    {
        $path = 'imports/test.csv';
        Storage::disk('local')->put($path, $csv);

        return ImportBatch::query()->create([
            'module' => 'general-ledger-opening-balance',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => 'test.csv',
            'disk' => 'local',
            'file_path' => $path,
        ]);
    }

    private const PREAMBLE = "PRINT LEDGER [Summary]\r\nPT Test Company\r\n01/01/2026 - 31/12/2026,,,,17/09/2026 10:09:44\r\n\r\n";

    private const HEADER = "Account Code,Account Description,Begining Balance,Total Debit,Total Credit,Net Activity,Ending Balance\r\n";

    public function test_matched_accounts_post_a_balanced_opening_balance_journal_entry(): void
    {
        $csv = self::PREAMBLE.self::HEADER
            // Asset (debit-normal): positive = debit.
            .'1100,Cash and Bank,1000000.00,0,0,0,1000000.00'."\r\n"
            // Equity (credit-normal in real bookkeeping, but the file's own convention is raw
            // debit-minus-credit regardless of type) — negative here means a credit line.
            .'3000,Owner\'s Equity,-1000000.00,0,0,0,-1000000.00'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status);
        $this->assertSame(2, $batch->success_rows);
        $this->assertSame(0, $batch->preview_summary['needs_review_rows']);

        $entry = JournalEntry::query()->whereDate('posting_date', '2025-12-31')->firstOrFail();
        $this->assertSame('submitted', $entry->status->value);
        $this->assertEquals(1000000, (float) $entry->total_debit);
        $this->assertEquals(1000000, (float) $entry->total_credit);

        $cash = ChartOfAccount::query()->where('code', '1100')->firstOrFail();
        $equity = ChartOfAccount::query()->where('code', '3000')->firstOrFail();
        $this->assertEquals(1000000, (float) $entry->lines()->where('chart_of_account_id', $cash->id)->firstOrFail()->debit);
        $this->assertEquals(1000000, (float) $entry->lines()->where('chart_of_account_id', $equity->id)->firstOrFail()->credit);

        // The whole point: GeneralLedgerService (a pure derived read model) now reports this as
        // the account's opening balance for any period starting on/after 2026-01-01, exactly like
        // any other posted Journal Entry — nothing was written to a "GL" table. Both read back as
        // +1,000,000: Cash's normal (debit) balance, and Equity's normal (credit) balance — GL's
        // own per-type sign convention re-derives correctly from the plain debit/credit lines this
        // import posts, regardless of the source file's own (type-agnostic) signed convention.
        $rows = app(GeneralLedgerService::class)->listAccounts(['date_from' => '2026-01-01']);
        $cashRow = collect($rows)->firstWhere(fn ($r) => $r['account']->id === $cash->id);
        $equityRow = collect($rows)->firstWhere(fn ($r) => $r['account']->id === $equity->id);
        $this->assertEquals(1000000, $cashRow['opening_balance']);
        $this->assertEquals(1000000, $equityRow['opening_balance']);
    }

    public function test_unmatched_account_code_is_flagged_and_plugged_without_blocking_the_batch(): void
    {
        $csv = self::PREAMBLE.self::HEADER
            .'1100,Cash and Bank,1000000.00,0,0,0,1000000.00'."\r\n"
            .'999.99.99,Unknown Legacy Account,-1000000.00,0,0,0,-1000000.00'."\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status);
        $vouchers = collect($batch->preview_summary['vouchers']);
        $this->assertSame('needs_review', $vouchers->first(fn ($v) => str_starts_with($v['document_number'], '999.99.99'))['status']);
        // 2, not 1: the unmatched account's own row, plus the suspense plug line itself that
        // absorbed it — both genuinely need a human to look at them.
        $this->assertSame(2, $batch->preview_summary['needs_review_rows']);

        $suspense = ChartOfAccount::query()->where('code', PrintLedgerImportService::SUSPENSE_ACCOUNT_CODE)->firstOrFail();
        $entry = JournalEntry::query()->whereDate('posting_date', '2025-12-31')->firstOrFail();
        // The skipped row was a credit (-1,000,000) — the suspense plug takes over that exact
        // line rather than the account this Chart of Accounts doesn't have.
        $this->assertEquals(1000000, (float) $entry->lines()->where('chart_of_account_id', $suspense->id)->firstOrFail()->credit);
        $this->assertEquals(1000000, (float) $entry->total_debit);
        $this->assertEquals(1000000, (float) $entry->total_credit);
    }

    public function test_missing_required_columns_fails_without_creating_anything(): void
    {
        $csv = "PRINT LEDGER [Summary]\r\nPT Test Company\r\n01/01/2026 - 31/12/2026\r\n\r\nAccount Code,Account Description\r\n1100,Cash and Bank\r\n";

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::FAILED, $batch->status);
        $this->assertStringContainsString('Kolom wajib', $batch->failure_reason);
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_reimporting_the_same_period_fails_to_avoid_double_counting(): void
    {
        $csv = self::PREAMBLE.self::HEADER
            .'1100,Cash and Bank,1000000.00,0,0,0,1000000.00'."\r\n"
            .'3000,Owner\'s Equity,-1000000.00,0,0,0,-1000000.00'."\r\n";

        $this->service->import($this->makeBatch($csv));
        $this->assertSame(1, JournalEntry::query()->count());

        $secondBatch = $this->makeBatch($csv);
        $this->service->import($secondBatch);
        $secondBatch->refresh();

        $this->assertEquals(ImportBatchStatus::FAILED, $secondBatch->status);
        $this->assertStringContainsString('Sudah ada Opening Balance', $secondBatch->failure_reason);
        $this->assertSame(1, JournalEntry::query()->count());
    }
}
