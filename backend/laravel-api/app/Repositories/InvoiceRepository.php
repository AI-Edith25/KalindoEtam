<?php

namespace App\Repositories;

use App\Enums\AccountsReceivableStatus;
use App\Models\Invoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class InvoiceRepository extends BaseRepository
{
    protected const EAGER = ['customer', 'salesOrder', 'salesOrders', 'branch', 'delivery.warehouse', 'deliveries', 'items.tax', 'tax', 'creator', 'updater', 'accountsReceivable.receiptEntryItems.receiptEntry.cashAccount'];

    public function __construct(Invoice $model)
    {
        parent::__construct($model);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()->with(self::EAGER)->latest('invoice_date')->paginate($perPage);
    }

    /** Same filtering shape as SalesOrderRepository::search(). */
    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()
            ->with(self::EAGER)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['invoice_type'] ?? null, fn ($query, $invoiceType) => $query->where('invoice_type', $invoiceType))
            ->when($filters['customer_id'] ?? null, fn ($query, $customerId) => $query->where('customer_id', $customerId))
            // Anchor-only: matches the primary Delivery, not every source Delivery on a merged Invoice.
            ->when($filters['delivery_id'] ?? null, fn ($query, $deliveryId) => $query->where('delivery_id', $deliveryId))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('invoice_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('invoice_date', '<=', $date))
            // Unpaid or partially paid AR. Draft/cancelled invoices have no accountsReceivable
            // row (cancel() deletes it — InvoiceService::cancel()), so whereHas excludes them
            // for free. EXISTS subquery, not a per-row check.
            ->when($filters['outstanding'] ?? null, fn ($query) => $query
                ->whereHas('accountsReceivable', fn ($sq) => $sq->whereIn('status', [
                    AccountsReceivableStatus::UNPAID,
                    AccountsReceivableStatus::PARTIALLY_PAID,
                ])))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($q) => $q->where('document_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($sq) => $sq->where('customer_name', 'like', "%{$search}%"))
            ))
            ->latest('invoice_date')
            ->paginate($perPage);
    }

    public function findOrFail(string $id): Model
    {
        return $this->model->query()->with(self::EAGER)->findOrFail($id);
    }

    /**
     * Unpaginated, for the Sales Report export (Summary/Detail). When $ids is given, it
     * replaces the whole filter chain rather than AND-ing with it — a checked-rows selection
     * on the Invoices list means "export exactly these", not "these narrowed further by
     * whatever filter happens to still be active" (deliberately different from
     * AccountsReceivableRepository::searchAll()'s own invoice_ids, which stays additive).
     */
    public function searchAll(array $filters, ?array $ids = null): Collection
    {
        if (! empty($ids)) {
            return $this->model->query()->with(self::EAGER)->whereIn('id', $ids)->latest('invoice_date')->get();
        }

        return $this->model->query()
            ->with(self::EAGER)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['invoice_type'] ?? null, fn ($query, $invoiceType) => $query->where('invoice_type', $invoiceType))
            ->when($filters['customer_id'] ?? null, fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('invoice_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('invoice_date', '<=', $date))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($q) => $q->where('document_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($sq) => $sq->where('customer_name', 'like', "%{$search}%"))
            ))
            ->latest('invoice_date')
            ->get();
    }
}
