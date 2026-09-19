<?php

namespace App\Services;

use App\Models\Item;
use App\Repositories\SalesOrderItemRepository;

/**
 * The one place "how much of this item can actually be sold from this warehouse right now" is
 * computed — used by ItemService::list() to enrich Sales Order's item lookup with `available_qty`
 * (physical stock minus what other active Sales Orders already committed against it). Mirrors
 * ItemPriceResolver's shape exactly.
 *
 * $warehouseId is null for every caller that isn't Sales Order's own item picker (Item Master,
 * Purchase Order's lookup — see PurchaseOrderLineItemTable.tsx, which never passes warehouse_id) —
 * apply() no-ops immediately in that case, so Purchase Order never pays for or sees this at all.
 */
class ItemStockResolver
{
    public function __construct(
        protected StockLedgerService $stockLedgerService,
        protected SalesOrderItemRepository $salesOrderItemRepository,
    ) {}

    /** @param  iterable<Item>  $items */
    public function apply(iterable $items, ?string $warehouseId): void
    {
        if ($warehouseId === null) {
            return;
        }

        $itemIds = collect($items)->pluck('id')->all();

        if ($itemIds === []) {
            return;
        }

        $physical = $this->stockLedgerService->peekBalances($itemIds, $warehouseId);
        $committed = $this->salesOrderItemRepository->committedQtyByItem($itemIds, $warehouseId);

        foreach ($items as $item) {
            $availableQty = ($physical[$item->id] ?? 0.0) - ($committed[$item->id] ?? 0.0);
            $item->setAttribute('available_qty', $availableQty);
        }
    }
}
