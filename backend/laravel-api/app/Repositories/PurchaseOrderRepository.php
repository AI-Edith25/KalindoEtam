<?php

namespace App\Repositories;

use App\Enums\DocumentStatus;
use App\Models\PurchaseOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderRepository extends BaseRepository
{
    protected const EAGER = ['supplier', 'items.item', 'tax'];

    public function __construct(PurchaseOrder $model)
    {
        parent::__construct($model);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()->with(self::EAGER)->latest('order_date')->paginate($perPage);
    }

    /**
     * Same filtering shape as AccountsPayableRepository::search() —
     * status is an exact match, date_from/date_to bound order_date,
     * search matches document_number or the supplier's name.
     */
    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()
            ->with(self::EAGER)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['supplier_id'] ?? null, fn ($query, $supplierId) => $query->where('supplier_id', $supplierId))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('order_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('order_date', '<=', $date))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($q) => $q->where('document_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($sq) => $sq->where('supplier_name', 'like', "%{$search}%"))
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

    /** Pending Tasks widget (docs/DASHBOARD_DESIGN.md §3) — same DRAFT status every list page's own Status filter already reads. */
    public function countDraft(): int
    {
        return $this->model->query()->where('status', DocumentStatus::DRAFT)->count();
    }

    /** Daily totals over a period — the Purchase Trend chart's only data source (docs/DASHBOARD_DESIGN.md §5). Same date column (order_date) totalForDate() already uses, just grouped instead of pinned to one day. */
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
