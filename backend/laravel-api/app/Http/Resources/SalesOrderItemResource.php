<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'item_code' => $this->whenLoaded('item', fn () => $this->item->item_code),
            'item_name' => $this->whenLoaded('item', fn () => $this->item->item_name),
            'uom' => $this->whenLoaded('item', fn () => $this->item->uom?->name),
            'qty' => $this->qty,
            'rate' => $this->rate,
            'amount' => $this->amount,
            'tax_id' => $this->tax_id,
            'tax' => new TaxResource($this->whenLoaded('tax')),
            'tax_amount' => $this->tax_amount,
            'delivered_qty' => $this->delivered_qty,
            'outstanding_qty' => $this->qty - $this->delivered_qty,
            // Any Delivery already referencing this line locks it against edit/removal on an
            // Approved order — see SalesOrderService::syncApprovedItems().
            'is_locked' => $this->whenLoaded('deliveryItems', fn () => $this->deliveryItems->isNotEmpty(), false),
        ];
    }
}
