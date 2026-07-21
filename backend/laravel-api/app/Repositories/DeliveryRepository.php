<?php

namespace App\Repositories;

use App\Models\Delivery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class DeliveryRepository extends BaseRepository
{
    protected const EAGER = ['customer', 'warehouse', 'salesOrder', 'items', 'invoice'];

    public function __construct(Delivery $model)
    {
        parent::__construct($model);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()->with(self::EAGER)->latest('delivery_date')->paginate($perPage);
    }

    /** Same filtering shape as GoodsReceiptRepository::search() — status exact match, date_from/date_to bound delivery_date, search matches document_number or the customer's name. */
    public function search(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()
            ->with(self::EAGER)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['warehouse_id'] ?? null, fn ($query, $warehouseId) => $query->where('warehouse_id', $warehouseId))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('delivery_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('delivery_date', '<=', $date))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($q) => $q->where('document_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($sq) => $sq->where('customer_name', 'like', "%{$search}%"))
            ))
            ->latest('delivery_date')
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
