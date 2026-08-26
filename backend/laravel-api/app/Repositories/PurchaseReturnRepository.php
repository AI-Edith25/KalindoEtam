<?php

namespace App\Repositories;

use App\Models\PurchaseReturn;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class PurchaseReturnRepository extends BaseRepository
{
    protected const EAGER = ['purchaseInvoice', 'supplier', 'items.purchaseInvoiceItem', 'items.item'];

    public function __construct(PurchaseReturn $model)
    {
        parent::__construct($model);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()->with(self::EAGER)->latest('return_date')->paginate($perPage);
    }

    /** Same filtering shape as CreditNoteRepository::search(). */
    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()
            ->with(self::EAGER)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['reason'] ?? null, fn ($query, $reason) => $query->where('reason', $reason))
            ->when($filters['supplier_id'] ?? null, fn ($query, $supplierId) => $query->where('supplier_id', $supplierId))
            ->when($filters['purchase_invoice_id'] ?? null, fn ($query, $invoiceId) => $query->where('purchase_invoice_id', $invoiceId))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('return_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('return_date', '<=', $date))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($q) => $q->where('document_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($sq) => $sq->where('supplier_name', 'like', "%{$search}%"))
                    ->orWhereHas('purchaseInvoice', fn ($sq) => $sq->where('document_number', 'like', "%{$search}%"))
            ))
            ->latest('return_date')
            ->paginate($perPage);
    }

    public function findOrFail(string $id): Model
    {
        return $this->model->query()->with(self::EAGER)->findOrFail($id);
    }

    /**
     * Unpaginated, for export. When $ids is given, it replaces the whole filter chain rather
     * than AND-ing with it. Mirrors CreditNoteRepository's search() shape.
     */
    public function searchAll(array $filters, ?array $ids = null): Collection
    {
        if (! empty($ids)) {
            return $this->model->query()->with(self::EAGER)->whereIn('id', $ids)->latest('return_date')->get();
        }

        return $this->model->query()
            ->with(self::EAGER)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['reason'] ?? null, fn ($query, $reason) => $query->where('reason', $reason))
            ->when($filters['supplier_id'] ?? null, fn ($query, $supplierId) => $query->where('supplier_id', $supplierId))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('return_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('return_date', '<=', $date))
            ->latest('return_date')
            ->get();
    }

    /**
     * Sum of every non-reversed, submitted Purchase Return's total_amount
     * against a Purchase Invoice — the running "already returned" figure
     * the validation guards check against. Mirrors
     * CreditNoteRepository::creditedTotalForInvoice(). Kept as a live query
     * (not just the accounts_payables.credited_amount cache) so a
     * corrupted cache can never silently under-validate.
     */
    public function creditedTotalForInvoice(string $purchaseInvoiceId): float
    {
        return (float) $this->model->query()
            ->where('purchase_invoice_id', $purchaseInvoiceId)
            ->where('is_reversed', false)
            ->where('status', 'submitted')
            ->sum('total_amount');
    }
}
