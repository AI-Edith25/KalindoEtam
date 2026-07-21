<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockAdjustmentItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'item_code' => $this->item_code,
            'item_name' => $this->item_name,
            'uom' => $this->uom,
            'system_qty' => $this->system_qty,
            'counted_qty' => $this->counted_qty,
            'difference_qty' => $this->difference_qty,
            'reason' => $this->reason,
        ];
    }
}
