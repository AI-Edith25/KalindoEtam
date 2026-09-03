<?php

namespace App\Services;

use App\Models\Item;

/**
 * The one place the "warehouse -> zone -> standard rate" fallback chain is computed — used by
 * ItemService::list() (which backs both the Item Prices matrix's read-only context and, more
 * importantly, Sales Order's item lookup once a warehouse is selected). Callers must eager-load
 * itemWarehousePrices (filtered to [$warehouseId, $mainWarehouseId]) and itemPrices (filtered to
 * the zone) before calling apply() — see ItemRepository::paginate().
 */
class ItemPriceResolver
{
    /** @param  iterable<Item>  $items */
    public function apply(iterable $items, ?string $warehouseId, ?string $mainWarehouseId): void
    {
        foreach ($items as $item) {
            $item->setAttribute('effective_rate', $this->resolve($item, $warehouseId, $mainWarehouseId));
        }
    }

    private function resolve(Item $item, ?string $warehouseId, ?string $mainWarehouseId): string
    {
        if ($warehouseId !== null && $item->relationLoaded('itemWarehousePrices')) {
            // "Sync to Main WH" — resolve live from the Main warehouse's own override instead of
            // this item's warehouse override, never physically copied into item_warehouse_prices.
            $targetWarehouseId = ($item->sync_to_main_wh && $mainWarehouseId) ? $mainWarehouseId : $warehouseId;
            $override = $item->itemWarehousePrices->firstWhere('warehouse_id', $targetWarehouseId);

            if ($override) {
                return $override->rate;
            }
        }

        if ($item->relationLoaded('itemPrices') && $item->itemPrices->first()) {
            return $item->itemPrices->first()->rate;
        }

        return $item->standard_rate;
    }
}
