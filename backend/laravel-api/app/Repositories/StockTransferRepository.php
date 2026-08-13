<?php

namespace App\Repositories;

use App\Models\StockTransfer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

class StockTransferRepository extends BaseRepository
{
    protected const EAGER = ['sourceWarehouse', 'destinationWarehouse', 'items'];

    public function __construct(StockTransfer $model)
    {
        parent::__construct($model);
    }

    /** Same filtering shape as StockAdjustmentRepository::search() — warehouse_id matches either side of the transfer. */
    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()
            ->with(self::EAGER)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query->where(
                fn ($q) => $q->where('source_warehouse_id', $warehouseId)->orWhere('destination_warehouse_id', $warehouseId)
            ))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('transfer_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('transfer_date', '<=', $date))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($q) => $q->where('document_number', 'like', "%{$search}%")
            ))
            ->latest('transfer_date')
            ->paginate($perPage);
    }

    public function findOrFail(string $id): Model
    {
        return $this->model->query()->with(self::EAGER)->findOrFail($id);
    }
}
