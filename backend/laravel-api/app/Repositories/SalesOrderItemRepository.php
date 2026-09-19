<?php

namespace App\Repositories;

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrderItem;
use Illuminate\Database\Eloquent\Model;

class SalesOrderItemRepository extends BaseRepository
{
    public function __construct(SalesOrderItem $model)
    {
        parent::__construct($model);
    }

    /**
     * "Committed" stock — the qty other active (non-cancelled) Sales Orders have already promised
     * to a customer for this item in this warehouse but haven't delivered yet. Not a stored column
     * anywhere; computed fresh from qty - delivered_qty, same source Delivery's own outstanding-qty
     * logic already reads. $excludeSalesOrderId omits the order currently being edited so its own
     * existing reservation is never counted against itself.
     *
     * @param  string[]  $itemIds
     * @return array<string, float> keyed by item_id, missing key means 0
     */
    public function committedQtyByItem(array $itemIds, string $warehouseId, ?string $excludeSalesOrderId = null): array
    {
        if ($itemIds === []) {
            return [];
        }

        return $this->model->query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->whereNull('sales_order_items.deleted_at')
            ->whereNull('sales_orders.deleted_at')
            ->where('sales_orders.warehouse_id', $warehouseId)
            ->where('sales_orders.status', '!=', SalesOrderStatus::CANCELLED->value)
            ->whereIn('sales_order_items.item_id', $itemIds)
            ->when($excludeSalesOrderId, fn ($query, $id) => $query->where('sales_orders.id', '!=', $id))
            ->selectRaw('sales_order_items.item_id, SUM(sales_order_items.qty - sales_order_items.delivered_qty) as committed_qty')
            ->groupBy('sales_order_items.item_id')
            ->get()
            ->pluck('committed_qty', 'item_id')
            ->map(fn ($qty) => (float) $qty)
            ->all();
    }

    /**
     * Eager-loads item.uom — callers (DeliveryService) always need the
     * item's code/name/uom for the delivery-line snapshot right after
     * resolving this, so loading it here avoids an N+1 per line.
     */
    public function findOrFail(string $id): Model
    {
        return $this->model->query()->with('item.uom')->findOrFail($id);
    }

    public function incrementDeliveredQty(SalesOrderItem $item, int $qty): void
    {
        $item->update(['delivered_qty' => $item->delivered_qty + $qty]);
    }
}
