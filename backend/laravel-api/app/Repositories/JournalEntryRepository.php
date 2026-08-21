<?php

namespace App\Repositories;

use App\Enums\DocumentStatus;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PaymentAllocation;
use App\Models\PaymentEntry;
use App\Models\ReceiptEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class JournalEntryRepository extends BaseRepository
{
    protected const EAGER = ['lines.chartOfAccount', 'lines.branch', 'referenceDocument', 'reverses', 'reversedBy', 'creator'];

    public function __construct(JournalEntry $model)
    {
        parent::__construct($model);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()->with(self::EAGER)->latest('posting_date')->paginate($perPage);
    }

    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->filteredQuery($filters)->with(self::EAGER)->latest('posting_date')->paginate($perPage);
    }

    /** Every filtered row, unpaginated — Export must cover the whole filtered set, not one page. */
    public function searchAll(array $filters): Collection
    {
        return $this->filteredQuery($filters)->with(['referenceDocument', 'creator'])->latest('posting_date')->get();
    }

    protected function filteredQuery(array $filters): Builder
    {
        return $this->model->query()
            ->when($filters['ids'] ?? null, fn ($query, $ids) => $query->whereIn('id', $ids))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['reference_type'] ?? null, fn ($query, $type) => $query->where('reference_type', $type))
            ->when($filters['account_id'] ?? null, fn ($query, $accountId) => $query->whereHas(
                'lines', fn ($lineQuery) => $lineQuery->where('chart_of_account_id', $accountId)
            ))
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->whereHasMorph(
                'referenceDocument',
                [Invoice::class, CreditNote::class, DebitNote::class, ReceiptEntry::class, PaymentEntry::class, PaymentAllocation::class],
                $this->branchMorphConstraint($branchId)
            ))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('posting_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('posting_date', '<=', $date))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($q) => $q->where('document_number', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
            ));
    }

    /**
     * Branch of the origin transaction, not journal_entry_lines.branch_id — that column is only ever
     * populated by the manual "New Journal Entry" form, never by a system-generated posting (see
     * JournalListRepository's own docblock). Each reference type reaches its Branch differently:
     * Invoice/Credit Note/Debit Note via their Sales Order(s) (an Invoice can span more than one,
     * see Invoice::salesOrders()), Receipt Entry/Payment Entry directly, Payment Allocation via its
     * Receipt Entry. Goods Receipt and manual entries (reference_type null) have no resolvable
     * branch — warehouses lost their branch_id column — so they're simply excluded from the match.
     */
    protected function branchMorphConstraint(string $branchId): \Closure
    {
        return function (Builder $query, string $type) use ($branchId) {
            match ($type) {
                Invoice::class => $query->whereHas('salesOrders', fn ($q) => $q->where('branch_id', $branchId)),
                CreditNote::class, DebitNote::class => $query->whereHas('invoice.salesOrders', fn ($q) => $q->where('branch_id', $branchId)),
                ReceiptEntry::class, PaymentEntry::class => $query->where('branch_id', $branchId),
                PaymentAllocation::class => $query->whereHas('receiptEntry', fn ($q) => $q->where('branch_id', $branchId)),
                default => $query,
            };
        };
    }

    public function findOrFail(string $id): Model
    {
        return $this->model->query()->with(self::EAGER)->findOrFail($id);
    }

    /**
     * Used by AccountingService::reverseForDocument() — the still-active posted entry for a given
     * source document, if any. `whereNull('reverses_id')` excludes reversal entries themselves: a
     * reversal is posted under the same reference_type/reference_id as the original but never gets
     * its own reversed_by_id set, so without this filter a second reverse+repost cycle on the same
     * reference (e.g. a second Invoice Rate correction) could match the leftover reversal entry
     * instead of the live one and reverse the wrong side of the ledger.
     */
    public function findActivePostedByReference(string $referenceType, string $referenceId): ?JournalEntry
    {
        return $this->model->query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('status', DocumentStatus::SUBMITTED)
            ->whereNull('reversed_by_id')
            ->whereNull('reverses_id')
            ->first();
    }

    /** Used by PeriodManagementService's closing validation — a Draft entry dated inside the period being closed is a real risk (docs/PERIOD_CLOSING_DESIGN.md §3.1). */
    public function countDraftsBetween(string $startDate, string $endDate): int
    {
        return $this->model->query()
            ->where('status', DocumentStatus::DRAFT)
            ->whereDate('posting_date', '>=', $startDate)
            ->whereDate('posting_date', '<=', $endDate)
            ->count();
    }
}
