<?php

namespace App\Services;

use App\Models\Item;

/**
 * Physical stock in a warehouse, exposed to Sales Order's item lookup as `available_qty` — used by
 * ItemService::list(). Sales Orders don't reserve stock, so this is the warehouse's on-hand balance.
 * Mirrors ItemPriceResolver's shape exactly.
 *
 * $warehouseId is null for every caller that isn't Sales Order's own item picker (Item Master,
 * Purchase Order's lookup — see PurchaseOrderLineItemTable.tsx, which never passes warehouse_id) —
 * apply() no-ops immediately in that case, so Purchase Order never pays for or sees this at all.
 */
class ItemStockResolver
{
    public function __construct(
        protected StockLedgerService $stockLedgerService,
    ) {}

    /** @param  iterable<Item>  $items */
    public function apply(iterable $items, ?string $warehouseId): void
    {
        if ($warehouseId === null) {
            return;
        }

        // Not collect($items)->pluck('id') — $items is often a LengthAwarePaginator, and collect()
        // on an Arrayable paginator calls its toArray(), which returns the pagination META array
        // (current_page, data, total, ...) instead of the rows, silently plucking 'id' off 13
        // unrelated keys and returning all-null. Plain iteration is what ItemPriceResolver already
        // does for the same reason.
        $itemIds = [];
        foreach ($items as $item) {
            $itemIds[] = $item->id;
        }

        if ($itemIds === []) {
            return;
        }

        $physical = $this->stockLedgerService->peekBalances($itemIds, $warehouseId);

        foreach ($items as $item) {
            $item->setAttribute('available_qty', $physical[$item->id] ?? 0.0);
        }
    }
}
