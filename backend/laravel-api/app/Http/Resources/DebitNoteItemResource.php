<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DebitNoteItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_item_id' => $this->invoice_item_id,
            'item_id' => $this->item_id,
            'item_code' => $this->item_code,
            'item_name' => $this->item_name,
            'uom' => $this->uom,
            'description' => $this->description,
            'qty_adjusted' => $this->qty_adjusted,
            'rate' => $this->rate,
            'amount' => $this->amount,
        ];
    }
}
