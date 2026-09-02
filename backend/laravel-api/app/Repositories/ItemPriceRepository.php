<?php

namespace App\Repositories;

use App\Models\ItemPrice;
use Illuminate\Database\Eloquent\Collection;

class ItemPriceRepository extends BaseRepository
{
    public function __construct(ItemPrice $model)
    {
        parent::__construct($model);
    }

    /** All overrides, eager-loaded for the item×zone matrix — small table, no pagination needed. */
    public function allWithRelations(): Collection
    {
        return $this->model->query()->with(['item', 'priceZone'])->get();
    }

    public function findByItemAndZone(string $itemId, string $priceZoneId): ?ItemPrice
    {
        return $this->model->query()
            ->where('item_id', $itemId)
            ->where('price_zone_id', $priceZoneId)
            ->first();
    }
}
