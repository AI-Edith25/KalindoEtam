<?php

namespace App\Repositories;

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class SalesOrderRepository extends BaseRepository
{
    protected const EAGER = ['customer', 'salesPerson', 'branch', 'termsOfPayment', 'tax', 'items.item.uom', 'items.tax'];

    public function __construct(SalesOrder $model)
    {
        parent::__construct($model);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()->with(self::EAGER)->latest('order_date')->paginate($perPage);
    }

    /** Same filtering shape as PurchaseOrderRepository::search() — status (single or multi) exact match, date_from/date_to bound order_date, search matches document_number or the customer's name. */
    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->applyFilters($this->model->query()->with(self::EAGER), $filters)
            ->latest('order_date')
            ->paginate($perPage);
    }

    /**
     * Unpaginated, for bulk export/print. When $ids is given, it replaces the whole filter
     * chain rather than AND-ing with it — a checked-rows selection on the list means "export
     * exactly these", not "these narrowed further by whatever filter happens to still be
     * active" — same contract as InvoiceRepository::searchAll().
     */
    public function searchAll(array $filters, ?array $ids = null): Collection
    {
        if (! empty($ids)) {
            return $this->model->query()->with(self::EAGER)->whereIn('id', $ids)->latest('order_date')->get();
        }

        return $this->applyFilters($this->model->query()->with(self::EAGER), $filters)
            ->latest('order_date')
            ->get();
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when(! empty($filters['status'] ?? null), fn ($q) => $q->whereIn('status', (array) $filters['status']))
            ->when($filters['customer_id'] ?? null, fn ($q, $customerId) => $q->where('customer_id', $customerId))
            ->when($filters['sales_person_id'] ?? null, fn ($q, $salesPersonId) => $q->where('sales_person_id', $salesPersonId))
            ->when($filters['branch_id'] ?? null, fn ($q, $branchId) => $q->where('branch_id', $branchId))
            ->when($filters['item_id'] ?? null, fn ($q, $itemId) => $q->whereHas('items', fn ($sq) => $sq->where('item_id', $itemId)))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('order_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('order_date', '<=', $date))
            // Same predicate as the New Delivery flow's eligible-orders filter (approved + not
            // fully delivered) — whereHas is an EXISTS subquery, not a per-row check.
            ->when($filters['outstanding'] ?? null, fn ($q) => $q
                ->where('status', SalesOrderStatus::APPROVED)
                ->whereHas('items', fn ($sq) => $sq->whereColumn('delivered_qty', '<', 'qty')))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(
                fn ($sq) => $sq->where('document_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($sq2) => $sq2->where('customer_name', 'like', "%{$search}%"))
            ));
    }

    public function findOrFail(string $id): Model
    {
        return $this->model->query()->with(self::EAGER)->findOrFail($id);
    }

    public function totalForDate(string $date): array
    {
        return [
            'total_amount' => (float) $this->model->query()->whereDate('order_date', $date)->sum('total_amount'),
            'count' => $this->model->query()->whereDate('order_date', $date)->count(),
        ];
    }

    public function recent(int $limit): Collection
    {
        return $this->model->query()->latest('created_at')->limit($limit)->get();
    }

    /** Pending Tasks widget (docs/DASHBOARD_DESIGN.md §3) — Sales Orders awaiting Approval, same status every list page's own Status filter already reads. */
    public function countDraft(): int
    {
        return $this->model->query()->where('status', SalesOrderStatus::SUBMITTED)->count();
    }

    /** Daily totals over a period — the Sales Trend chart's only data source (docs/DASHBOARD_DESIGN.md §5). Same date column (order_date) totalForDate() already uses, just grouped instead of pinned to one day. */
    public function totalsByDateRange(string $dateFrom, string $dateTo): Collection
    {
        return $this->model->query()
            ->whereBetween('order_date', [$dateFrom, $dateTo])
            ->selectRaw('DATE(order_date) as date, SUM(total_amount) as total, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }
}
