<?php

namespace App\Services;

use App\Enums\PeriodStatus;
use App\Exceptions\BusinessException;
use App\Repositories\AccountingPeriodRepository;

/**
 * The single enforcement point for Period Closing — everything that can
 * ever write journal_entries routes through JournalEntryService, and
 * JournalEntryService calls this before every write (see its create/
 * update/post). No other service duplicates this check. See
 * docs/PERIOD_CLOSING_DESIGN.md §1.
 */
class PeriodLockService
{
    public function __construct(protected AccountingPeriodRepository $accountingPeriodRepository) {}

    /**
     * Fail-open: if no AccountingPeriod row covers $postingDate at all, the
     * operation is allowed — closing has zero effect until a period is
     * actually set up and closed. Only an explicitly CLOSED period blocks.
     */
    public function assertOpen(string $postingDate, ?string $companyId = null): void
    {
        $period = $this->accountingPeriodRepository->containing($postingDate, $companyId);

        if ($period?->status === PeriodStatus::CLOSED) {
            throw new BusinessException("Posting date {$postingDate} falls in a closed accounting period ({$period->name}) — postings are not allowed.");
        }
    }
}
