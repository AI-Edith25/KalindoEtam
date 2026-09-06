<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * reserved_qty/reorder_level are always null — explicit placeholders per
 * the sprint brief ("placeholder if backend does not yet support it"),
 * not fabricated numbers. Neither concept exists anywhere in this schema.
 */
class StockBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currentQty = (int) $this->current_qty;
        $totalValue = round((float) $this->total_value, 2);

        return [
            'item_id' => $this->item_id,
            'item_code' => $this->item_code,
            'item_name' => $this->item_name,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->warehouse_name,
            'uom' => $this->uom,
            'current_qty' => $currentQty,
            'reserved_qty' => null,
            'available_qty' => $currentQty,
            'reorder_level' => null,
            'total_value' => $totalValue,
            'avg_cost' => $currentQty > 0 ? round($totalValue / $currentQty, 2) : 0,
        ];
    }
}
