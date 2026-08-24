<?php

namespace App\Repositories;

use App\Enums\DocumentStatus;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * Journal-line-level read query behind the Journal List export — one row per
 * journal_entries record whose source document is a Receipt Entry/Payment
 * Entry (Cash Book Transaction, per its ticket definition), with every one of
 * that entry's lines expanded by JournalListExport, not just its cash/bank
 * leg. This is a different shape from CashBookRepository's document-level
 * screen query: the export walks full vouchers (matching the legacy
 * xlsJournalList(*).xlsx files line-for-line), the screen shows one row per
 * document.
 *
 * Returns a query builder (never ->get()) so the export can chunk through it
 * with WithChunkReading instead of loading the whole filtered set (100k+ rows
 * in the real Cashbook export) into memory at once.
 */
class JournalListRepository
{
    public function cashBookJournalEntries(array $filters, string $view): Builder
    {
        $referenceTypes = match ($view) {
            'receipt' => ['receipt_entry'],
            'payment' => ['payment_entry'],
            default => ['receipt_entry', 'payment_entry'],
        };

        return JournalEntry::query()
            ->select('journal_entries.*')
            ->selectRaw('COALESCE(receipt_entries.branch_id, payment_entries.branch_id) as resolved_branch_id')
            ->selectRaw('COALESCE(receipt_entries.reference_number, payment_entries.reference_number) as resolved_reference_number')
            // "Transaction" is the source document's own number (e.g. the Official Receipt/Payment
            // Voucher number), not journal_entries.document_number — confirmed against the legacy
            // files, whose Transaction column holds values like "OR002021"/"PVBCASMD-000001".
            ->selectRaw('COALESCE(receipt_entries.document_number, payment_entries.document_number) as resolved_document_number')
            ->leftJoin('receipt_entries', function ($join) {
                $join->on('journal_entries.reference_id', '=', 'receipt_entries.id')
                    ->where('journal_entries.reference_type', '=', 'receipt_entry');
            })
            ->leftJoin('payment_entries', function ($join) {
                $join->on('journal_entries.reference_id', '=', 'payment_entries.id')
                    ->where('journal_entries.reference_type', '=', 'payment_entry');
            })
            ->whereIn('journal_entries.reference_type', $referenceTypes)
            ->where('journal_entries.status', $filters['status'] ?? DocumentStatus::SUBMITTED->value)
            ->whereNull('journal_entries.deleted_at')
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('journal_entries.posting_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('journal_entries.posting_date', '<=', $date))
            ->when($filters['branch_id'] ?? null, fn ($q, $branchId) => $q->where(
                fn ($q2) => $q2->where('receipt_entries.branch_id', $branchId)->orWhere('payment_entries.branch_id', $branchId)
            ))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('journal_entries.document_number', 'like', "%{$search}%"))
            ->with(['lines' => fn ($q) => $q->orderBy('id'), 'lines.chartOfAccount'])
            ->orderBy('journal_entries.posting_date')
            ->orderBy('journal_entries.document_number');
    }
}
