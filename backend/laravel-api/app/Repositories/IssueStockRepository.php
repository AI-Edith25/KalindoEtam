<?php

namespace App\Repositories;

use App\Models\IssueStock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

class IssueStockRepository extends BaseRepository
{
    protected const EAGER = ['warehouse', 'items'];

    public function __construct(IssueStock $model)
    {
        parent::__construct($model);
    }

    /** Same filtering shape as OpeningStockRepository::search() — Warehouse, Status, date range (the ticket's explicit filter list). */
    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()
            ->with(self::EAGER)
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query->where('warehouse_id', $warehouseId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('issue_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('issue_date', '<=', $date))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where('document_number', 'like', "%{$search}%"))
            ->latest('issue_date')
            ->paginate($perPage);
    }

    public function findOrFail(string $id): Model
    {
        return $this->model->query()->with(self::EAGER)->findOrFail($id);
    }
}
