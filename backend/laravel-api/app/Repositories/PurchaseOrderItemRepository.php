<?php

namespace App\Repositories;

use App\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItemRepository extends BaseRepository
{
    public function __construct(PurchaseOrderItem $model)
    {
        parent::__construct($model);
    }

    /**
     * Eager-loads item.uom — callers (GoodsReceiptService) always need the
     * item's code/name/uom for the receipt-line snapshot right after
     * resolving this, so loading it here avoids an N+1 per line.
     */
    public function findOrFail(string $id): Model
    {
        return $this->model->query()->with('item.uom')->findOrFail($id);
    }

    public function incrementReceivedQty(PurchaseOrderItem $item, int $qty): void
    {
        $item->update(['received_qty' => $item->received_qty + $qty]);
    }
}
