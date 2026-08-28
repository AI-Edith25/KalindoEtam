<?php

namespace App\Repositories;

use App\Models\DebitNote;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class DebitNoteRepository extends BaseRepository
{
    protected const EAGER = ['invoice', 'customer', 'items.invoiceItem', 'items.item'];

    public function __construct(DebitNote $model)
    {
        parent::__construct($model);
    }

    /** Same filtering shape as CreditNoteRepository::search() — status is single or multi. */
    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->applyFilters($this->model->query()->with(self::EAGER), $filters)
            ->latest('debit_note_date')
            ->paginate($perPage);
    }

    /** Unpaginated, for bulk export/print. $ids (when given) replaces the whole filter chain — same "checked rows win outright" contract as InvoiceRepository::searchAll(). */
    public function searchAll(array $filters, ?array $ids = null): Collection
    {
        if (! empty($ids)) {
            return $this->model->query()->with(self::EAGER)->whereIn('id', $ids)->latest('debit_note_date')->get();
        }

        return $this->applyFilters($this->model->query()->with(self::EAGER), $filters)
            ->latest('debit_note_date')
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
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('debit_note_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('debit_note_date', '<=', $date))
            ->when($filters['min_amount'] ?? null, fn ($q, $amount) => $q->where('total_amount', '>=', $amount))
            ->when($filters['max_amount'] ?? null, fn ($q, $amount) => $q->where('total_amount', '<=', $amount))
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
}
