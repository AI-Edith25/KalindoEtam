<?php

namespace App\Repositories;

use App\Models\CreditNote;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

    /** Same filtering shape as InvoiceRepository::search(). */
    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()
            ->with(self::EAGER)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['reason'] ?? null, fn ($query, $reason) => $query->where('reason', $reason))
            ->when($filters['customer_id'] ?? null, fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->when($filters['invoice_id'] ?? null, fn ($query, $invoiceId) => $query->where('invoice_id', $invoiceId))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('credit_note_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('credit_note_date', '<=', $date))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($q) => $q->where('document_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($sq) => $sq->where('customer_name', 'like', "%{$search}%"))
                    ->orWhereHas('invoice', fn ($sq) => $sq->where('document_number', 'like', "%{$search}%"))
            ))
            ->latest('credit_note_date')
            ->paginate($perPage);
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
}
