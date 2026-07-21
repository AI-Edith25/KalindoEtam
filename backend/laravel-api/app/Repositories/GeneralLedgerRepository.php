<?php

namespace App\Repositories;

use App\Enums\DocumentStatus;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Pure read queries over the existing journal_entries/journal_entry_lines
 * tables — no writes, no new accounting table, nothing cached. Every method
 * here is a fresh aggregate or a fresh ordered fetch; see
 * docs/GENERAL_LEDGER_DESIGN.md §2/§3/§6 for why nothing is ever persisted.
 *
 * Ordering is fixed project-wide (see GENERAL_LEDGER_DESIGN.md's mandatory
 * "Deterministic Running Balance" rule): posting_date, then document_number,
 * then the line's own id. Every method that returns ordered rows uses
 * exactly this order — never an alternative.
 */
class GeneralLedgerRepository
{
    /**
     * Grouped-by-account sum of every posted line strictly before
     * $filters['date_from'] — the Ledger List's Opening Balance column.
     * Empty (no accounts precede an unbounded range) when date_from is unset.
     *
     * @return array<string, array{debit: float, credit: float}>
     */
    public function openingTotalsByAccount(array $filters): array
    {
        if (empty($filters['date_from'])) {
            return [];
        }

        $query = $this->baseQuery($filters)->whereDate('je.posting_date', '<', $filters['date_from']);

        return $this->groupedTotals($query);
    }

    /**
     * Grouped-by-account sum within the filtered date range (or unbounded
     * if no dates given) — the Ledger List's Debit/Credit columns.
     *
     * @return array<string, array{debit: float, credit: float}>
     */
    public function periodTotalsByAccount(array $filters): array
    {
        $query = $this->applyDateRange($this->baseQuery($filters), $filters);

        return $this->groupedTotals($query);
    }

    /** Single-account variant of openingTotalsByAccount(). */
    public function openingTotalForAccount(string $accountId, array $filters): array
    {
        if (empty($filters['date_from'])) {
            return ['debit' => 0.0, 'credit' => 0.0];
        }

        $query = $this->baseQuery(['account_id' => $accountId] + $filters)
            ->whereDate('je.posting_date', '<', $filters['date_from']);

        return $this->singleTotal($query);
    }

    /** Single-account variant of periodTotalsByAccount() — the Ledger Detail header's Ending Balance figure. */
    public function periodTotalForAccount(string $accountId, array $filters): array
    {
        $query = $this->applyDateRange($this->baseQuery(['account_id' => $accountId] + $filters), $filters);

        return $this->singleTotal($query);
    }

    /**
     * Sum of every matching line strictly before the given deterministic
     * cursor — used to derive the opening balance for page N>1 of an
     * account's line list without re-summing every prior page (see
     * GENERAL_LEDGER_DESIGN.md §6). Bounded by the same date range as the
     * line list itself, since the cursor only ever points within it.
     */
    public function totalBeforeCursor(string $accountId, array $filters, string $postingDate, string $documentNumber, string $lineId): array
    {
        $query = $this->applyDateRange($this->baseQuery(['account_id' => $accountId] + $filters), $filters);

        $query->where(function (Builder $q) use ($postingDate, $documentNumber, $lineId) {
            $q->whereDate('je.posting_date', '<', $postingDate)
                ->orWhere(function (Builder $q2) use ($postingDate, $documentNumber, $lineId) {
                    $q2->whereDate('je.posting_date', $postingDate)
                        ->where(function (Builder $q3) use ($documentNumber, $lineId) {
                            $q3->where('je.document_number', '<', $documentNumber)
                                ->orWhere(function (Builder $q4) use ($documentNumber, $lineId) {
                                    $q4->where('je.document_number', $documentNumber)
                                        ->where('jel.id', '<', $lineId);
                                });
                        });
                });
        });

        return $this->singleTotal($query);
    }

    /**
     * The paginated, deterministically-ordered line list for one account's
     * Ledger Detail — posting_date, document_number, id (mandated project-wide).
     */
    public function linesForAccount(string $accountId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = JournalEntryLine::query()
            ->select('journal_entry_lines.*')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entry_lines.chart_of_account_id', $accountId)
            ->where('journal_entries.status', $filters['status'] ?? DocumentStatus::SUBMITTED->value)
            ->whereNull('journal_entry_lines.deleted_at')
            ->whereNull('journal_entries.deleted_at')
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('journal_entries.posting_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('journal_entries.posting_date', '<=', $date))
            ->when($filters['reference_type'] ?? null, fn ($q, $type) => $q->where('journal_entries.reference_type', $type))
            ->when($filters['branch_id'] ?? null, fn ($q, $branchId) => $q->where('journal_entry_lines.branch_id', $branchId))
            ->when($filters['company_id'] ?? null, fn ($q, $companyId) => $q->whereIn(
                'journal_entry_lines.branch_id',
                fn ($sub) => $sub->select('id')->from('branches')->where('company_id', $companyId)->whereNull('deleted_at')
            ))
            ->when($filters['reference_number'] ?? null, fn ($q, $search) => $q->whereIn(
                'journal_entry_lines.journal_entry_id', $this->journalEntryIdsMatchingReferenceNumber($search)
            ))
            ->with(['journalEntry.referenceDocument', 'chartOfAccount'])
            ->orderBy('journal_entries.posting_date')
            ->orderBy('journal_entries.document_number')
            ->orderBy('journal_entry_lines.id');

        return $query->paginate($perPage);
    }

    /** Every account filter (status/reference type/branch/company/reference number) applies uniformly to opening, running, and ending balance — never just to the displayed rows — so the numbers on screen always reconcile. */
    protected function baseQuery(array $filters): Builder
    {
        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->whereNull('jel.deleted_at')
            ->whereNull('je.deleted_at')
            ->where('je.status', $filters['status'] ?? DocumentStatus::SUBMITTED->value);

        if (! empty($filters['account_id'])) {
            $query->where('jel.chart_of_account_id', $filters['account_id']);
        }

        if (! empty($filters['reference_type'])) {
            $query->where('je.reference_type', $filters['reference_type']);
        }

        if (! empty($filters['branch_id'])) {
            $query->where('jel.branch_id', $filters['branch_id']);
        }

        if (! empty($filters['company_id'])) {
            $query->whereIn('jel.branch_id', function ($sub) use ($filters) {
                $sub->select('id')->from('branches')->where('company_id', $filters['company_id'])->whereNull('deleted_at');
            });
        }

        if (! empty($filters['reference_number'])) {
            $query->whereIn('je.id', $this->journalEntryIdsMatchingReferenceNumber($filters['reference_number']));
        }

        return $query;
    }

    protected function applyDateRange(Builder $query, array $filters): Builder
    {
        // whereDate(), not where() — posting_date is stored with a time component by Eloquent's
        // date cast (e.g. "2026-07-19 00:00:00"), so a plain string comparison against a bare
        // "2026-07-19" filter would silently exclude same-day rows from an inclusive boundary.
        if (! empty($filters['date_from'])) {
            $query->whereDate('je.posting_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('je.posting_date', '<=', $filters['date_to']);
        }

        return $query;
    }

    protected function groupedTotals(Builder $query): array
    {
        $rows = $query->select('jel.chart_of_account_id')
            ->selectRaw('COALESCE(SUM(jel.debit), 0) as debit, COALESCE(SUM(jel.credit), 0) as credit')
            ->groupBy('jel.chart_of_account_id')
            ->get();

        return $rows->keyBy('chart_of_account_id')
            ->map(fn ($row) => ['debit' => (float) $row->debit, 'credit' => (float) $row->credit])
            ->all();
    }

    protected function singleTotal(Builder $query): array
    {
        $row = $query->selectRaw('COALESCE(SUM(jel.debit), 0) as debit, COALESCE(SUM(jel.credit), 0) as credit')->first();

        return ['debit' => (float) ($row->debit ?? 0), 'credit' => (float) ($row->credit ?? 0)];
    }

    /**
     * Filters the Reference Number search down to matching Journal Entry
     * ids via the polymorphic referenceDocument relation (Invoice/Credit
     * Note/Debit Note/Receipt Entry/Payment Allocation) — resolved once,
     * reused by every caller that needs to scope by it. See
     * GENERAL_LEDGER_DESIGN.md Open Question 2 on this join's cost.
     */
    protected function journalEntryIdsMatchingReferenceNumber(string $search): array
    {
        return JournalEntry::query()
            ->whereHasMorph('referenceDocument', ['*'], fn ($q) => $q->where('document_number', 'like', "%{$search}%"))
            ->pluck('id')
            ->all();
    }
}
