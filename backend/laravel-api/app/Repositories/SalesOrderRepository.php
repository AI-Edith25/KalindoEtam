<?php

namespace App\Repositories;

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class SalesOrderRepository extends BaseRepository
{
    protected const EAGER = ['customer', 'salesPerson', 'branch', 'termsOfPayment', 'tax', 'items.item.uom'];

    public function __construct(SalesOrder $model)
    {
        parent::__construct($model);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()->with(self::EAGER)->latest('order_date')->paginate($perPage);
    }

    /** Same filtering shape as PurchaseOrderRepository::search() — status exact match, date_from/date_to bound order_date, search matches document_number or the customer's name. */
    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()
            ->with(self::EAGER)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['customer_id'] ?? null, fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->when($filters['sales_person_id'] ?? null, fn ($query, $salesPersonId) => $query->where('sales_person_id', $salesPersonId))
            ->when($filters['branch_id'] ?? null, fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['item_id'] ?? null, fn ($query, $itemId) => $query->whereHas('items', fn ($sq) => $sq->where('item_id', $itemId)))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('order_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('order_date', '<=', $date))
            // Same predicate as the New Delivery flow's eligible-orders filter (approved + not
            // fully delivered) — whereHas is an EXISTS subquery, not a per-row check.
            ->when($filters['outstanding'] ?? null, fn ($query) => $query
                ->where('status', SalesOrderStatus::APPROVED)
                ->whereHas('items', fn ($sq) => $sq->whereColumn('delivered_qty', '<', 'qty')))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($q) => $q->where('document_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($sq) => $sq->where('customer_name', 'like', "%{$search}%"))
            ))
            ->latest('order_date')
            ->paginate($perPage);
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
