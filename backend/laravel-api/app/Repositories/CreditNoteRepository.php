<?php

namespace App\Repositories;

use App\Models\CreditNote;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class CreditNoteRepository extends BaseRepository
{
    protected const EAGER = ['invoice', 'customer', 'items.invoiceItem', 'items.item'];

    public function __construct(CreditNote $model)
    {
        parent::__construct($model);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()->with(self::EAGER)->latest('credit_note_date')->paginate($perPage);
    }

    /** Same filtering shape as InvoiceRepository::search() — status is single or multi. */
    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->applyFilters($this->model->query()->with(self::EAGER), $filters)
            ->latest('credit_note_date')
            ->paginate($perPage);
    }

    /** Unpaginated, for bulk export/print. $ids (when given) replaces the whole filter chain — same "checked rows win outright" contract as InvoiceRepository::searchAll(). */
    public function searchAll(array $filters, ?array $ids = null): Collection
    {
        if (! empty($ids)) {
            return $this->model->query()->with(self::EAGER)->whereIn('id', $ids)->latest('credit_note_date')->get();
        }

        return $this->applyFilters($this->model->query()->with(self::EAGER), $filters)
            ->latest('credit_note_date')
            ->get();
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when(! empty($filters['status'] ?? null), fn ($q) => $q->whereIn('status', (array) $filters['status']))
            ->when($filters['reason'] ?? null, fn ($q, $reason) => $q->where('reason', $reason))
            ->when($filters['customer_id'] ?? null, fn ($q, $customerId) => $q->where('customer_id', $customerId))
            ->when($filters['sales_person_id'] ?? null, fn ($q, $salesPersonId) => $q
                ->whereHas('invoice.salesOrder', fn ($sq) => $sq->where('sales_person_id', $salesPersonId)))
            ->when($filters['invoice_id'] ?? null, fn ($q, $invoiceId) => $q->where('invoice_id', $invoiceId))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('credit_note_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('credit_note_date', '<=', $date))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(
                fn ($sq) => $sq->where('document_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($sq2) => $sq2->where('customer_name', 'like', "%{$search}%"))
                    ->orWhereHas('invoice', fn ($sq2) => $sq2->where('document_number', 'like', "%{$search}%"))
            ));
    }

    public function findOrFail(string $id): Model
    {
        return $this->model->query()->with(self::EAGER)->findOrFail($id);
    }

    /**
     * Sum of every non-reversed, submitted Credit Note's total_amount
     * against an Invoice — the running "already credited" figure the
     * design's validation guards check against. Kept as a live query (not
     * just the accounts_receivables.credited_amount cache) so a corrupted
     * cache can never silently under-validate. Filtering to status =
     * submitted already excludes any draft, including the one a caller
     * might currently be validating an update against — a draft has not
     * committed anything yet.
     */
    public function creditedTotalForInvoice(string $invoiceId): float
    {
        return (float) $this->model->query()
            ->where('invoice_id', $invoiceId)
            ->where('is_reversed', false)
            ->where('status', 'submitted')
            ->sum('total_amount');
    }

    /** AR Aging report's Summary footer "MTD/YTD CN" figures — company-wide, ignores every report filter/selection. */
    public function creditNoteTotal(Carbon $from, Carbon $to): float
    {
        return (float) $this->model->query()
            ->where('is_reversed', false)
            ->where('status', 'submitted')
            ->whereDate('credit_note_date', '>=', $from)
            ->whereDate('credit_note_date', '<=', $to)
            ->sum('total_amount');
    }
}
