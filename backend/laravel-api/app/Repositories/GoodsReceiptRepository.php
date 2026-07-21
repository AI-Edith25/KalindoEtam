<?php

namespace App\Repositories;

use App\Models\GoodsReceipt;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class GoodsReceiptRepository extends BaseRepository
{
    protected const EAGER = ['supplier', 'warehouse', 'purchaseOrder', 'items'];

    public function __construct(GoodsReceipt $model)
    {
        parent::__construct($model);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()->with(self::EAGER)->latest('receipt_date')->paginate($perPage);
    }

    /** Same filtering shape as PurchaseOrderRepository::search() — search matches document_number or the supplier's name. */
    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()
            ->with(self::EAGER)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query->where('warehouse_id', $warehouseId))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('receipt_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('receipt_date', '<=', $date))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($q) => $q->where('document_number', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($sq) => $sq->where('supplier_name', 'like', "%{$search}%"))
            ))
            ->latest('receipt_date')
            ->paginate($perPage);
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
