<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'item_code' => $this->whenLoaded('item', fn () => $this->item->item_code),
            'item_name' => $this->whenLoaded('item', fn () => $this->item->item_name),
            'allow_over_receipt' => $this->whenLoaded('item', fn () => (bool) $this->item->allow_over_receipt),
            'item_qty_category' => $this->whenLoaded('item', fn () => $this->item->qty_category),
            'item_uom' => $this->whenLoaded('item', fn () => $this->item->uom?->name),
            'qty' => $this->qty,
            'rate' => $this->rate,
            'amount' => $this->amount,
            'tax_id' => $this->tax_id,
            'tax' => new TaxResource($this->whenLoaded('tax')),
            'tax_amount' => $this->tax_amount,
            'received_qty' => $this->received_qty,
            'outstanding_qty' => $this->qty - $this->received_qty,
        ];
    }
}
