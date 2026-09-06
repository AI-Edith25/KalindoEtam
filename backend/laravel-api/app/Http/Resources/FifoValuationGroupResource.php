<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One (item, warehouse) group row for the FIFO Layers report — wraps the plain object FifoValuationService::grouped() builds, not an Eloquent model. */
class FifoValuationGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'item_id' => $this->item_id,
            'warehouse_id' => $this->warehouse_id,
            'item_code' => $this->item_code,
            'item_name' => $this->item_name,
            'warehouse_name' => $this->warehouse_name,
            'qty_remaining' => $this->qty_remaining,
            'total_value' => $this->total_value,
            'weighted_average_cost' => $this->weighted_average_cost,
            'layers' => FifoLayerDetailResource::collection($this->layers),
        ];
    }
}
