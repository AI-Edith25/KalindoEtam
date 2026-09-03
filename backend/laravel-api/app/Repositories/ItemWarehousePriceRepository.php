<?php

namespace App\Repositories;

use App\Models\ItemWarehousePrice;
use Illuminate\Database\Eloquent\Collection;

class ItemWarehousePriceRepository extends BaseRepository
{
    public function __construct(ItemWarehousePrice $model)
    {
        parent::__construct($model);
    }

    /** All overrides, eager-loaded for the item×warehouse matrix — small table, no pagination needed. */
    public function allWithRelations(): Collection
    {
        return $this->model->query()->with(['item', 'warehouse'])->get();
    }

    public function findByItemAndWarehouse(string $itemId, string $warehouseId): ?ItemWarehousePrice
    {
        return $this->model->query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->first();
    }

    /**
     * Locks the superset of rows a batch might touch (every item_id × warehouse_id combo
     * present in the batch), fixed order, so two concurrent batches can never deadlock — same
     * shape as AccountsReceivableRepository::lockManyForUpdate().
     *
     * @return Collection<string, ItemWarehousePrice> keyed by "item_id:warehouse_id"
     */
    public function lockManyForBatch(array $itemIds, array $warehouseIds): Collection
    {
        return $this->model->query()
            ->whereIn('item_id', array_unique($itemIds))
            ->whereIn('warehouse_id', array_unique($warehouseIds))
            ->orderBy('item_id')
            ->orderBy('warehouse_id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (ItemWarehousePrice $row) => "{$row->item_id}:{$row->warehouse_id}");
    }
}
