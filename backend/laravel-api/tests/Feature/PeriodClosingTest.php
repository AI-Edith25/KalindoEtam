<?php

namespace Tests\Feature;

use App\Enums\PeriodStatus;
use App\Exceptions\BusinessException;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Role;
use App\Models\User;
use App\Services\JournalEntryService;
use App\Services\PeriodManagementService;
use Database\Seeders\BalanceSheetMappingSeeder;
use Database\Seeders\CashFlowMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Database\Seeders\ReportAccountMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PeriodClosingTest extends TestCase
{
    use RefreshDatabase;

    protected JournalEntryService $journalEntryService;
    protected PeriodManagementService $periodManagementService;
    protected ChartOfAccount $cashAccount;
    protected ChartOfAccount $revenueAccount;
    protected User $adminUser;
    protected User $regularUser;
    protected FiscalYear $fiscalYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(ReportAccountMappingSeeder::class);
        $this->seed(BalanceSheetMappingSeeder::class);
        $this->seed(CashFlowMappingSeeder::class);

        $this->journalEntryService = app(JournalEntryService::class);
        $this->periodManagementService = app(PeriodManagementService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => '2026-01-01']);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);

        $this->cashAccount = ChartOfAccount::query()->where('code', '1100')->firstOrFail();
        $this->revenueAccount = ChartOfAccount::query()->where('code', '4000')->firstOrFail();

        $adminRole = Role::query()->create(['name' => 'Admin', 'guard_name' => 'web']);
        $this->adminUser = User::query()->create(['name' => 'Admin User', 'email' => 'admin-test@example.com', 'password' => 'password']);
        $this->adminUser->assignRole($adminRole);

        $this->regularUser = User::query()->create(['name' => 'Staff User', 'email' => 'staff-test@example.com', 'password' => 'password']);

        $this->fiscalYear = $this->periodManagementService->createFiscalYear([
            'company_id' => $company->id,
            'name' => 'FY2026',
            'start_date' => '2026-01-01',
        ]);
    }

    protected function periodFor(string $name): AccountingPeriod
    {
        return $this->fiscalYear->accountingPeriods()->where('name', $name)->firstOrFail();
    }

    protected function postJournalEntry(string $postingDate, float $amount = 10000): void
    {
        $entry = $this->journalEntryService->create([
            'posting_date' => $postingDate,
            'description' => 'Test posting',
            'lines' => [
                ['chart_of_account_id' => $this->cashAccount->id, 'debit' => $amount, 'credit' => 0],
                ['chart_of_account_id' => $this->revenueAccount->id, 'debit' => 0, 'credit' => $amount],
            ],
        ]);
        $this->approveDocument($entry);
        $this->journalEntryService->post($entry);
    }

    public function test_period_status_has_exactly_two_states(): void
    {
        $this->assertEquals(['open', 'closed'], array_map(fn (PeriodStatus $case) => $case->value, PeriodStatus::cases()));
    }

    public function test_creating_a_fiscal_year_generates_twelve_monthly_open_periods(): void
    {
        $periods = $this->fiscalYear->accountingPeriods()->orderBy('start_date')->get();

        $this->assertCount(12, $periods);
        $this->assertTrue($periods->every(fn (AccountingPeriod $p) => $p->status === PeriodStatus::OPEN));
        $this->assertEquals('2026-01-01', $periods->first()->start_date->toDateString());
        $this->assertEquals('January 2026', $periods->first()->name);
        $this->assertEquals('2026-12-31', $periods->last()->end_date->toDateString());
    }

    public function test_journal_entry_create_is_allowed_when_no_period_record_covers_the_date(): void
    {
        // 2030 is far outside the generated FY2026 calendar — fail-open, per design §1.
        $this->postJournalEntry('2030-01-15');
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_journal_entry_create_is_blocked_once_its_period_is_closed(): void
    {
        $january = $this->periodFor('January 2026');
        $this->periodManagementService->close($january, $this->adminUser);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessageMatches('/closed accounting period/');

        $this->postJournalEntry('2026-01-15');
    }

    public function test_journal_entry_post_is_blocked_independently_of_create(): void
    {
        // Draft first while the period is still open (create() succeeds)...
        $entry = $this->journalEntryService->create([
            'posting_date' => '2026-01-15',
            'lines' => [
                ['chart_of_account_id' => $this->cashAccount->id, 'debit' => 5000, 'credit' => 0],
                ['chart_of_account_id' => $this->revenueAccount->id, 'debit' => 0, 'credit' => 5000],
            ],
        ]);

        // ...then the period closes by other means before post() runs (bypassing close()'s own
        // "no draft in period" validation, to isolate post()'s independent re-check).
        $january = $this->periodFor('January 2026');
        $january->update(['status' => PeriodStatus::CLOSED]);

        $this->expectException(BusinessException::class);
        $this->journalEntryService->post($entry);
    }

    public function test_journal_entry_update_is_blocked_when_new_posting_date_falls_in_a_closed_period(): void
    {
        $entry = $this->journalEntryService->create([
            'posting_date' => '2026-03-15',
            'lines' => [
                ['chart_of_account_id' => $this->cashAccount->id, 'debit' => 5000, 'credit' => 0],
                ['chart_of_account_id' => $this->revenueAccount->id, 'debit' => 0, 'credit' => 5000],
            ],
        ]);

        $january = $this->periodFor('January 2026');
        $this->periodManagementService->close($january, $this->adminUser);

        $this->expectException(BusinessException::class);
        $this->journalEntryService->update($entry, ['posting_date' => '2026-01-15']);
    }

    public function test_close_fails_when_a_draft_entry_exists_in_the_period(): void
    {
        $this->journalEntryService->create([
            'posting_date' => '2026-01-10',
            'lines' => [
                ['chart_of_account_id' => $this->cashAccount->id, 'debit' => 1000, 'credit' => 0],
                ['chart_of_account_id' => $this->revenueAccount->id, 'debit' => 0, 'credit' => 1000],
            ],
        ]);

        $january = $this->periodFor('January 2026');

        try {
            $this->periodManagementService->close($january, $this->adminUser);
            $this->fail('Expected BusinessException was not thrown.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('draft', $e->getMessage());
        }

        $this->assertEquals(PeriodStatus::OPEN, $january->fresh()->status);
    }

    public function test_close_fails_when_the_prior_period_is_still_open(): void
    {
        $february = $this->periodFor('February 2026');

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessageMatches('/prior/i');

        $this->periodManagementService->close($february, $this->adminUser);
    }

    public function test_close_succeeds_and_records_an_audit_trail_entry(): void
    {
        $january = $this->periodFor('January 2026');

        $closed = $this->periodManagementService->close($january, $this->adminUser);

        $this->assertEquals(PeriodStatus::CLOSED, $closed->status);
        $this->assertEquals($this->adminUser->id, $closed->closed_by_id);
        $this->assertNotNull($closed->closed_at);

        $this->assertDatabaseHas('document_timelines', [
            'subject_type' => (new AccountingPeriod)->getMorphClass(),
            'subject_id' => $january->id,
            'action' => 'closed',
        ]);
    }

    public function test_reopen_requires_admin_role(): void
    {
        $january = $this->periodFor('January 2026');
        $this->periodManagementService->close($january, $this->adminUser);

        $this->expectException(BusinessException::class);
        $this->periodManagementService->reopen($january->fresh(), $this->regularUser);
    }

    public function test_reopen_succeeds_for_admin_and_preserves_close_history_while_recording_the_reopen(): void
    {
        $january = $this->periodFor('January 2026');
        $closed = $this->periodManagementService->close($january, $this->adminUser);

        $reopened = $this->periodManagementService->reopen($closed, $this->adminUser);

        $this->assertEquals(PeriodStatus::OPEN, $reopened->status);
        $this->assertEquals($this->adminUser->id, $reopened->reopened_by_id);
        $this->assertNotNull($reopened->reopened_at);
        // closed_by_id/closed_at are preserved, not cleared, per design §4.
        $this->assertEquals($this->adminUser->id, $reopened->closed_by_id);
        $this->assertNotNull($reopened->closed_at);

        $this->assertDatabaseHas('document_timelines', [
            'subject_type' => (new AccountingPeriod)->getMorphClass(),
            'subject_id' => $january->id,
            'action' => 'reopened',
        ]);
    }

    public function test_reopen_fails_if_a_later_period_is_already_closed(): void
    {
        $january = $this->periodFor('January 2026');
        $february = $this->periodFor('February 2026');

        $this->periodManagementService->close($january, $this->adminUser);
        $this->periodManagementService->close($february->fresh(), $this->adminUser);

        $this->expectException(BusinessException::class);
        $this->periodManagementService->reopen($january->fresh(), $this->adminUser);
    }
}
