<?php

namespace App\Repositories;

use App\Enums\AccountsReceivableStatus;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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

    /** Same filtering shape as SalesOrderRepository::search() — status is single or multi. */
    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->applyFilters($this->model->query()->with(self::EAGER), $filters)
            ->latest('invoice_date')
            ->paginate($perPage);
    }

    public function findOrFail(string $id): Model
    {
        return $this->model->query()->with(self::EAGER)->findOrFail($id);
    }

    /**
     * Unpaginated, for bulk export/print and the Sales Report export (Summary/Detail). When
     * $ids is given, it replaces the whole filter chain rather than AND-ing with it — a
     * checked-rows selection on the Invoices list means "export exactly these", not "these
     * narrowed further by whatever filter happens to still be active" (deliberately different
     * from AccountsReceivableRepository::searchAll()'s own invoice_ids, which stays additive).
     */
    public function searchAll(array $filters, ?array $ids = null): Collection
    {
        if (! empty($ids)) {
            return $this->model->query()->with(self::EAGER)->whereIn('id', $ids)->latest('invoice_date')->get();
        }

        return $this->applyFilters($this->model->query()->with(self::EAGER), $filters)
            ->latest('invoice_date')
            ->get();
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when(! empty($filters['status'] ?? null), fn ($q) => $q->whereIn('status', (array) $filters['status']))
            ->when($filters['invoice_type'] ?? null, fn ($q, $invoiceType) => $q->where('invoice_type', $invoiceType))
            ->when($filters['customer_id'] ?? null, fn ($q, $customerId) => $q->where('customer_id', $customerId))
            // Anchor-only: matches the primary Delivery/Sales Order, not every source on a merged Invoice.
            ->when($filters['delivery_id'] ?? null, fn ($q, $deliveryId) => $q->where('delivery_id', $deliveryId))
            ->when($filters['sales_person_id'] ?? null, fn ($q, $salesPersonId) => $q
                ->whereHas('salesOrder', fn ($sq) => $sq->where('sales_person_id', $salesPersonId)))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('invoice_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('invoice_date', '<=', $date))
            // Unpaid or partially paid AR. Draft/cancelled invoices have no accountsReceivable
            // row (cancel() deletes it — InvoiceService::cancel()), so whereHas excludes them
            // for free. EXISTS subquery, not a per-row check.
            ->when($filters['outstanding'] ?? null, fn ($q) => $q
                ->whereHas('accountsReceivable', fn ($sq) => $sq->whereIn('status', [
                    AccountsReceivableStatus::UNPAID,
                    AccountsReceivableStatus::PARTIALLY_PAID,
                ])))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(
                fn ($sq) => $sq->where('document_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($sq2) => $sq2->where('customer_name', 'like', "%{$search}%"))
            ));
    }

    /** AR Aging report's Summary footer "MTD/YTD SALES" figures — company-wide, ignores every report filter/selection. */
    public function salesTotal(Carbon $from, Carbon $to): float
    {
        return (float) $this->model->query()
            ->where('status', 'submitted')
            ->whereDate('invoice_date', '>=', $from)
            ->whereDate('invoice_date', '<=', $to)
            ->sum('grand_total');
    }
}
