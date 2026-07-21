<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockInResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'warehouse_id' => $this->warehouse_id,
            'qty_in' => $this->qty_in,
            'date_in' => $this->date_in?->format('Y-m-d'),
            'created_at' => $this->created_at,
        ];
    }
}
