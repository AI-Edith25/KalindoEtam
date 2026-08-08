<?php

namespace App\Repositories;

use App\Enums\DocumentStatus;
use App\Models\JournalEntryLine;
use Illuminate\Support\Collection;

/**
 * Pure read query over journal_entries/journal_entry_lines, scoped to lines
 * whose account is a cash/bank account — the data source for the Journal
 * List report. No writes, no new accounting table, same posture as
 * GeneralLedgerRepository (which this deliberately does not modify or
 * extend — a separate, narrowly-scoped repository instead).
 *
 * Branch is resolved via the source document (receipt_entries.branch_id /
 * payment_entries.branch_id), not journal_entry_lines.branch_id — that
 * column exists but is never populated by any business module (see
 * docs/GENERAL_LEDGER_DESIGN.md §0). Only these two document types ever
 * post to a cash/bank account line, so a LEFT JOIN to each, gated on
 * reference_type inside the join condition, resolves Branch without
 * touching journal_entry_lines.branch_id or any frozen module.
 */
class JournalListRepository
{
    /** @return Collection<int, JournalEntryLine> */
    public function cashBankLines(array $filters): Collection
    {
        return JournalEntryLine::query()
            ->select('journal_entry_lines.*')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_entry_lines.chart_of_account_id')
            ->leftJoin('receipt_entries', function ($join) {
                $join->on('journal_entries.reference_id', '=', 'receipt_entries.id')
                    ->where('journal_entries.reference_type', '=', 'receipt_entry');
            })
            ->leftJoin('payment_entries', function ($join) {
                $join->on('journal_entries.reference_id', '=', 'payment_entries.id')
                    ->where('journal_entries.reference_type', '=', 'payment_entry');
            })
            ->where('chart_of_accounts.is_cash_bank', true)
            ->where('journal_entries.status', $filters['status'] ?? DocumentStatus::SUBMITTED->value)
            ->whereNull('journal_entry_lines.deleted_at')
            ->whereNull('journal_entries.deleted_at')
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('journal_entries.posting_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('journal_entries.posting_date', '<=', $date))
            ->when($filters['branch_id'] ?? null, fn ($q, $branchId) => $q->where(
                fn ($q2) => $q2->where('receipt_entries.branch_id', $branchId)->orWhere('payment_entries.branch_id', $branchId)
            ))
            ->with(['chartOfAccount', 'journalEntry.referenceDocument', 'journalEntry.lines.chartOfAccount'])
            ->orderBy('journal_entries.posting_date')
            ->orderBy('journal_entries.document_number')
            ->orderBy('journal_entry_lines.id')
            ->get();
    }
}
