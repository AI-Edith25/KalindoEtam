<?php

namespace App\Services;

use App\Enums\PeriodStatus;
use App\Exceptions\BusinessException;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\User;
use App\Repositories\AccountingPeriodRepository;
use App\Repositories\FiscalYearRepository;
use App\Repositories\JournalEntryRepository;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates Fiscal Year / Accounting Period setup and the close/reopen
 * workflow. Deliberately posts zero journal entries anywhere in this class
 * — Period Closing is a lock, not an accounting operation (Year-End
 * Closing, which would post real closing entries, is out of scope). See
 * docs/PERIOD_CLOSING_DESIGN.md §2.
 */
class PeriodManagementService
{
    public function __construct(
        protected FiscalYearRepository $fiscalYearRepository,
        protected AccountingPeriodRepository $accountingPeriodRepository,
        protected JournalEntryRepository $journalEntryRepository,
        protected BalanceSheetService $balanceSheetService,
        protected CashFlowService $cashFlowService,
        protected DocumentTimelineService $documentTimelineService,
        protected AuditLogService $auditLogService,
    ) {}

    /** @return Collection<int, FiscalYear> */
    public function listFiscalYears(): Collection
    {
        return $this->fiscalYearRepository->allOrderedByStartDate();
    }

    public function listPeriods(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->accountingPeriodRepository->search($filters, $perPage);
    }

    /** Creates a Fiscal Year spanning 12 months from start_date and generates its 12 monthly Accounting Periods (all Open) — the SME-friendly default, no manual 12x creation. */
    public function createFiscalYear(array $data): FiscalYear
    {
        return DB::transaction(function () use ($data) {
            $startDate = Carbon::parse($data['start_date']);
            $endDate = $startDate->copy()->addYear()->subDay();

            $fiscalYear = $this->fiscalYearRepository->create([
                'company_id' => $data['company_id'],
                'name' => $data['name'],
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
            ]);

            $this->generateMonthlyPeriods($fiscalYear);

            return $fiscalYear->fresh('accountingPeriods');
        });
    }

    /**
     * The §3 validation checklist, structured so the frontend can show
     * exactly which checks passed/failed before confirming a close — not
     * just a single throw. close() reuses this same result.
     *
     * @return array<int, array{key: string, label: string, passed: bool, detail: ?string}>
     */
    public function runValidation(AccountingPeriod $period): array
    {
        $checks = [];

        $draftCount = $this->journalEntryRepository->countDraftsBetween(
            $period->start_date->toDateString(),
            $period->end_date->toDateString(),
        );
        $checks[] = [
            'key' => 'no_draft_entries',
            'label' => 'No draft Journal Entries in this period',
            'passed' => $draftCount === 0,
            'detail' => $draftCount > 0 ? "{$draftCount} draft ".($draftCount === 1 ? 'entry' : 'entries').' found' : null,
        ];

        // Reused directly, never re-derived — BalanceSheetService/CashFlowService already compute
        // and log-warn on these exact identities (docs/PERIOD_CLOSING_DESIGN.md §3).
        $balanceSheet = $this->balanceSheetService->summarize(['as_of_date' => $period->end_date->toDateString()]);
        $checks[] = [
            'key' => 'balance_sheet_balanced',
            'label' => 'Accounting equation balances (Assets = Liabilities + Equity)',
            'passed' => $balanceSheet['is_balanced'],
            'detail' => null,
        ];

        $cashFlow = $this->cashFlowService->summarize([
            'date_from' => $period->start_date->toDateString(),
            'date_to' => $period->end_date->toDateString(),
        ]);
        $checks[] = [
            'key' => 'cash_flow_balanced',
            'label' => 'Cash Flow reconciles (Opening Cash + Net Movement = Closing Cash)',
            'passed' => $cashFlow['is_balanced'],
            'detail' => null,
        ];

        $preceding = $this->accountingPeriodRepository->preceding($period);
        $priorClosed = ! $preceding || $preceding->status === PeriodStatus::CLOSED;
        $checks[] = [
            'key' => 'prior_period_closed',
            'label' => 'Prior accounting period is already closed',
            'passed' => $priorClosed,
            'detail' => $preceding && ! $priorClosed ? "{$preceding->name} is still open" : null,
        ];

        return $checks;
    }

    public function close(AccountingPeriod $period, User $user): AccountingPeriod
    {
        return DB::transaction(function () use ($period, $user) {
            if ($period->status !== PeriodStatus::OPEN) {
                throw new BusinessException('Only an open accounting period can be closed.');
            }

            $checks = $this->runValidation($period);
            $failures = array_values(array_filter($checks, fn (array $check) => ! $check['passed']));

            if (! empty($failures)) {
                $messages = array_map(fn (array $check) => $check['label'].($check['detail'] ? " ({$check['detail']})" : ''), $failures);
                throw new BusinessException('Cannot close this period: '.implode('; ', $messages).'.');
            }

            $this->accountingPeriodRepository->update($period, [
                'status' => PeriodStatus::CLOSED->value,
                'closed_by_id' => $user->id,
                'closed_at' => now(),
            ]);

            $this->documentTimelineService->record($period, 'closed', "Closed by {$user->name}", ['validation' => $checks]);

            return $period->fresh(['fiscalYear', 'closedBy', 'reopenedBy']);
        });
    }

    public function reopen(AccountingPeriod $period, User $user): AccountingPeriod
    {
        // This codebase's first real enforcement of its otherwise-dormant Role/Permission
        // system (docs/PERIOD_CLOSING_DESIGN.md §0/§4) — every other FormRequest::authorize()
        // in this app returns true unconditionally; this is deliberately not one of them.
        // Checked (and logged) outside DB::transaction — a denial must never be rolled back
        // by the exception that reports it, same reasoning as ApprovalService::decide().
        if (! $user->hasRole('Admin')) {
            $this->auditLogService->record('period_reopen_denied', 'journal_entry', "Denied reopen of period \"{$period->name}\" by non-admin user {$user->id}.");

            throw new BusinessException('Only an administrator can reopen a closed accounting period.', 403);
        }

        return DB::transaction(function () use ($period, $user) {
            if ($period->status !== PeriodStatus::CLOSED) {
                throw new BusinessException('Only a closed accounting period can be reopened.');
            }

            if ($this->accountingPeriodRepository->hasLaterClosedPeriod($period)) {
                throw new BusinessException('Only the most recently closed accounting period can be reopened.');
            }

            // closed_by_id/closed_at are deliberately preserved, not cleared — the row always
            // shows the latest close/reopen pair; DocumentTimeline holds the full history.
            $this->accountingPeriodRepository->update($period, [
                'status' => PeriodStatus::OPEN->value,
                'reopened_by_id' => $user->id,
                'reopened_at' => now(),
            ]);

            $this->documentTimelineService->record($period, 'reopened', "Reopened by {$user->name}");

            return $period->fresh(['fiscalYear', 'closedBy', 'reopenedBy']);
        });
    }

    protected function generateMonthlyPeriods(FiscalYear $fiscalYear): void
    {
        $cursor = $fiscalYear->start_date->copy();

        for ($i = 0; $i < 12; $i++) {
            $periodStart = $cursor->copy();
            $periodEnd = $cursor->copy()->endOfMonth();

            $this->accountingPeriodRepository->create([
                'fiscal_year_id' => $fiscalYear->id,
                'name' => $periodStart->format('F Y'),
                'start_date' => $periodStart->toDateString(),
                'end_date' => $periodEnd->toDateString(),
                'status' => PeriodStatus::OPEN->value,
            ]);

            $cursor->addMonthNoOverflow()->startOfMonth();
        }
    }
}
