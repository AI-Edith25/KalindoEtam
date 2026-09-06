<?php

namespace App\Repositories;

use App\Models\OpeningStock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

class OpeningStockRepository extends BaseRepository
{
    protected const EAGER = ['warehouse', 'items', 'importBatch'];

    public function __construct(OpeningStock $model)
    {
        parent::__construct($model);
    }

    /** Same filtering shape as StockAdjustmentRepository::search(), plus warehouse_id (the ticket's explicit filter list). */
    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()
            ->with(self::EAGER)
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query->where('warehouse_id', $warehouseId))
            ->when($filters['import_batch_id'] ?? null, fn ($query, $batchId) => $query->where('import_batch_id', $batchId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('cutoff_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('cutoff_date', '<=', $date))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where('document_number', 'like', "%{$search}%"))
            ->latest('cutoff_date')
            ->paginate($perPage);
    }

    public function findOrFail(string $id): Model
    {
        return $this->model->query()->with(self::EAGER)->findOrFail($id);
    }
}
