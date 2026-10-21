<?php

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\ChartOfAccount;
use App\Services\JournalEntryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every posting path in this app (manual Journal Posting, and every business module via
 * AccountingService::postForDocument()) funnels through JournalEntryService::create()/update(),
 * so this is the single choke point for "an account with children can never be posted to
 * directly" — see JournalEntryService::assertSubledgerRequirement()'s own docblock.
 */
class JournalEntryHeaderAccountValidationTest extends TestCase
{
    use RefreshDatabase;

    protected JournalEntryService $journalEntryService;

    protected ChartOfAccount $headerAccount;

    protected ChartOfAccount $detailAccount;

    protected ChartOfAccount $otherDetailAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->journalEntryService = app(JournalEntryService::class);

        // 112 PIUTANG USAHA DAN LAINNYA (header, no postings of its own) over four children.
        $this->headerAccount = ChartOfAccount::query()->where('code', '112')->firstOrFail();
        // 112.02 (Piutang Karyawan), not 112.01 — the latter carries subledger_type::CUSTOMER and
        // would need a customer_id on top of what this test is actually exercising.
        $this->detailAccount = ChartOfAccount::query()->where('code', '112.02')->firstOrFail();
        $this->otherDetailAccount = ChartOfAccount::query()->where('code', '1100')->firstOrFail();
    }

    protected function lines(ChartOfAccount $debitAccount): array
    {
        return [
            ['chart_of_account_id' => $debitAccount->id, 'debit' => 10000, 'credit' => 0],
            ['chart_of_account_id' => $this->otherDetailAccount->id, 'debit' => 0, 'credit' => 10000],
        ];
    }

    public function test_create_rejects_a_line_posted_to_a_header_account_with_a_clear_message(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('112 - PIUTANG USAHA DAN LAINNYA" adalah akun header/parent dan tidak bisa diposting langsung');

        $this->journalEntryService->create([
            'posting_date' => now()->toDateString(),
            'lines' => $this->lines($this->headerAccount),
        ]);
    }

    public function test_create_allows_a_line_posted_to_a_detail_account(): void
    {
        $entry = $this->journalEntryService->create([
            'posting_date' => now()->toDateString(),
            'lines' => $this->lines($this->detailAccount),
        ]);

        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseHas('journal_entry_lines', ['journal_entry_id' => $entry->id, 'chart_of_account_id' => $this->detailAccount->id]);
    }

    public function test_update_rejects_replacing_lines_with_one_posted_to_a_header_account(): void
    {
        $entry = $this->journalEntryService->create([
            'posting_date' => now()->toDateString(),
            'lines' => $this->lines($this->detailAccount),
        ]);

        $this->expectException(BusinessException::class);

        $this->journalEntryService->update($entry, ['lines' => $this->lines($this->headerAccount)]);
    }

    /** skip_subledger_check is also the escape hatch for the legacy ledger/journal importers — see the service's own docblock for why both checks share one flag. */
    public function test_skip_subledger_check_also_bypasses_the_header_account_check(): void
    {
        $entry = $this->journalEntryService->create([
            'posting_date' => now()->toDateString(),
            'lines' => $this->lines($this->headerAccount),
            'skip_subledger_check' => true,
        ]);

        $this->assertDatabaseHas('journal_entry_lines', ['journal_entry_id' => $entry->id, 'chart_of_account_id' => $this->headerAccount->id]);
    }
}
