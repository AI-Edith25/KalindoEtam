<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OpeningStockItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'item_code' => $this->item_code,
            'item_name' => $this->item_name,
            'uom' => $this->uom,
            'qty_category' => $this->qty_category,
            'qty' => $this->qty,
            'unit_cost' => $this->unit_cost,
            'amount' => $this->amount,
        ];
    }
}
