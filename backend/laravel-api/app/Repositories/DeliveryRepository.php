<?php

namespace App\Repositories;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class DeliveryRepository extends BaseRepository
{
    protected const EAGER = ['customer', 'warehouse', 'salesOrder.salesPerson', 'salesOrder.tax', 'items.tax', 'invoices', 'termsOfPayment'];

    public function __construct(Delivery $model)
    {
        parent::__construct($model);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()->with(self::EAGER)->latest('delivery_date')->paginate($perPage);
    }

    /** Same filtering shape as GoodsReceiptRepository::search() — status (single or multi) exact match, date_from/date_to bound delivery_date, search matches document_number or the customer's name. */
    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->applyFilters($this->model->query()->with(self::EAGER), $filters)
            ->latest('delivery_date')
            ->paginate($perPage);
    }

    /** Unpaginated, for bulk export/print. $ids (when given) replaces the whole filter chain — same "checked rows win outright" contract as InvoiceRepository::searchAll(). */
    public function searchAll(array $filters, ?array $ids = null): Collection
    {
        if (! empty($ids)) {
            return $this->model->query()->with(self::EAGER)->whereIn('id', $ids)->latest('delivery_date')->get();
        }

        return $this->applyFilters($this->model->query()->with(self::EAGER), $filters)
            ->latest('delivery_date')
            ->get();
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when(! empty($filters['status'] ?? null), fn ($q) => $q->whereIn('status', (array) $filters['status']))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $warehouseId) => $q->where('warehouse_id', $warehouseId))
            ->when($filters['customer_id'] ?? null, fn ($q, $customerId) => $q->where('customer_id', $customerId))
            ->when($filters['sales_person_id'] ?? null, fn ($q, $salesPersonId) => $q
                ->whereHas('salesOrder', fn ($sq) => $sq->where('sales_person_id', $salesPersonId)))
            ->when($filters['item_id'] ?? null, fn ($q, $itemId) => $q->whereHas('items', fn ($sq) => $sq->where('item_id', $itemId)))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('delivery_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('delivery_date', '<=', $date))
            // Same predicate as the New Invoice flow's eligible-deliveries filter (complete +
            // not yet invoiced) — whereDoesntHave is an EXISTS subquery, not a per-row check.
            ->when($filters['outstanding'] ?? null, fn ($q) => $q
                ->where('status', DeliveryStatus::COMPLETE)
                ->whereDoesntHave('invoices'))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(
                fn ($sq) => $sq->where('document_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($sq2) => $sq2->where('customer_name', 'like', "%{$search}%"))
            ));
    }

    public function findOrFail(string $id): Model
    {
        return $this->model->query()->with(self::EAGER)->findOrFail($id);
    }

    public function recent(int $limit): Collection
    {
        return $this->model->query()->with('items')->latest('created_at')->limit($limit)->get();
    }
}
