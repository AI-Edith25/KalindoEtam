<?php

namespace App\Repositories;

use App\Enums\DocumentStatus;
use App\Models\CreditNote;
use App\Models\Invoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sales Journal's two screen tabs (Sales Invoice, Credit Note) — document-level, one row per
 * document. Reuses SalesListingRepository::query() as-is (its own public, unpaginated entry point
 * over the same Invoice/Credit Note union Sales Listing's own screen uses — same Sales
 * Person/Branch resolution, same SalesListingRowResource shape), just pinning `type` to one value
 * instead of leaving it open the way Sales Listing's own "All" filter does.
 *
 * Export data (exportInvoices()/exportCreditNotes()) is a different, item-level shape entirely —
 * see SalesJournalExport's own docblock for why this can't reuse journal_entries.
 */
class SalesJournalRepository
{
    public function __construct(protected SalesListingRepository $salesListingRepository) {}

    public function paginateInvoices(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->salesListingRepository->query([...$filters, 'type' => 'invoice'], 'date', 'desc')->paginate($perPage);
    }

    public function paginateCreditNotes(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->salesListingRepository->query([...$filters, 'type' => 'credit_note'], 'date', 'desc')->paginate($perPage);
    }

    /**
     * Every filtered Invoice, items+tax+salesPerson+branch+customer eager-loaded — a Builder,
     * never ->get(), so SalesJournalExport can chunk through it (the real Sales export is
     * 172k+ physical rows once exploded per item, confirmed against JournalList_Sales.xlsx —
     * loading every Invoice+items into memory at once the way a plain Collection would is not
     * viable, same reasoning as JournalListRepository::cashBookJournalEntries()).
     */
    public function exportInvoiceQuery(array $filters): Builder
    {
        return Invoice::query()
            ->with(['items.tax', 'tax', 'salesPerson', 'branch', 'salesOrder.branch', 'customer'])
            ->whereNull('deleted_at')
            ->where('status', DocumentStatus::SUBMITTED->value)
            ->when($filters['date_from'] ?? null, fn (Builder $q, $d) => $q->whereDate('invoice_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $d) => $q->whereDate('invoice_date', '<=', $d))
            ->when($filters['customer_id'] ?? null, fn (Builder $q, $v) => $q->where('customer_id', $v))
            ->when($filters['branch_id'] ?? null, fn (Builder $q, $v) => $q->where(
                fn (Builder $q2) => $q2->where('branch_id', $v)->orWhereHas('salesOrder', fn (Builder $q3) => $q3->where('branch_id', $v))
            ))
            ->when($filters['search'] ?? null, fn (Builder $q, $s) => $q->where('document_number', 'like', "%{$s}%"))
            ->orderBy('invoice_date')
            ->orderBy('document_number');
    }

    /** Every filtered Credit Note, items+parent Invoice(+salesPerson/branch/salesOrder)+customer eager-loaded — a Builder, never ->get(), same chunking reasoning as exportInvoiceQuery(). */
    public function exportCreditNoteQuery(array $filters): Builder
    {
        return CreditNote::query()
            ->with(['items', 'invoice.salesPerson', 'invoice.branch', 'invoice.salesOrder.branch', 'customer'])
            ->whereNull('deleted_at')
            ->where('status', DocumentStatus::SUBMITTED->value)
            ->where('is_reversed', false)
            ->when($filters['date_from'] ?? null, fn (Builder $q, $d) => $q->whereDate('credit_note_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $d) => $q->whereDate('credit_note_date', '<=', $d))
            ->when($filters['customer_id'] ?? null, fn (Builder $q, $v) => $q->where('customer_id', $v))
            ->when($filters['branch_id'] ?? null, fn (Builder $q, $v) => $q->where(
                fn (Builder $q2) => $q2->whereHas('invoice', fn (Builder $q3) => $q3->where('branch_id', $v)
                    ->orWhereHas('salesOrder', fn (Builder $q4) => $q4->where('branch_id', $v)))
            ))
            ->when($filters['search'] ?? null, fn (Builder $q, $s) => $q->where('document_number', 'like', "%{$s}%"))
            ->orderBy('credit_note_date')
            ->orderBy('document_number');
    }
}
