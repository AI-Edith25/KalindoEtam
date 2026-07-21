<?php

namespace App\Repositories;

use App\Models\SalesOrderItem;
use Illuminate\Database\Eloquent\Model;

class SalesOrderItemRepository extends BaseRepository
{
    public function __construct(SalesOrderItem $model)
    {
        parent::__construct($model);
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
