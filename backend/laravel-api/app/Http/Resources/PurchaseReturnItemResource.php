<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReturnItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_invoice_item_id' => $this->purchase_invoice_item_id,
            'item_id' => $this->item_id,
            'warehouse_id' => $this->warehouse_id,
            'item_code' => $this->item_code,
            'item_name' => $this->item_name,
            'uom' => $this->uom,
            'qty_returned' => $this->qty_returned,
            'rate' => $this->rate,
            'amount' => $this->amount,
        ];
    }
}
