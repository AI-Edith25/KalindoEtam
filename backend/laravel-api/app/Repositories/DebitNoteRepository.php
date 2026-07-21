<?php

namespace App\Repositories;

use App\Models\DebitNote;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

class DebitNoteRepository extends BaseRepository
{
    protected const EAGER = ['invoice', 'customer', 'items.invoiceItem', 'items.item'];

    public function __construct(DebitNote $model)
    {
        parent::__construct($model);
    }

    /** Same filtering shape as CreditNoteRepository::search(). */
    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()
            ->with(self::EAGER)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['reason'] ?? null, fn ($query, $reason) => $query->where('reason', $reason))
            ->when($filters['customer_id'] ?? null, fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->when($filters['invoice_id'] ?? null, fn ($query, $invoiceId) => $query->where('invoice_id', $invoiceId))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('debit_note_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('debit_note_date', '<=', $date))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($q) => $q->where('document_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($sq) => $sq->where('customer_name', 'like', "%{$search}%"))
                    ->orWhereHas('invoice', fn ($sq) => $sq->where('document_number', 'like', "%{$search}%"))
            ))
            ->latest('debit_note_date')
            ->paginate($perPage);
    }

    public function findOrFail(string $id): Model
    {
        return $this->model->query()->with(self::EAGER)->findOrFail($id);
    }
}
