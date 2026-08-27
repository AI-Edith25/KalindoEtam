<?php

namespace App\Repositories;

use App\Models\Item;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

class ItemRepository extends BaseRepository
{
    public function __construct(Item $model)
    {
        parent::__construct($model);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()->with(['itemGroup', 'uom', 'purchaseTax', 'salesTax'])->paginate($perPage);
    }

    public function findOrFail(string $id): Model
    {
        return $this->model->query()->with(['itemGroup', 'uom', 'purchaseTax', 'salesTax'])->findOrFail($id);
    }

    public function updateCurrentStock(Item $item, int|float $balanceQty): void
    {
        $item->update(['current_stock' => $balanceQty]);
    }

    public function stockSummary(): array
    {
        return [
            'total_items' => $this->model->query()->count(),
            'total_stock_qty' => (float) $this->model->query()->sum('current_stock'),
            'zero_stock_items' => $this->model->query()->where('current_stock', 0)->count(),
        ];
    }

    public function lowStock(int $threshold, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()
            ->with(['itemGroup', 'uom', 'purchaseTax', 'salesTax'])
            ->where('current_stock', '<=', $threshold)
            ->orderBy('current_stock')
            ->paginate($perPage);
    }
}
