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
            // The line's own UOM (falls back to the item's base UOM name) — what qty/rate are in.
            'uom' => $this->whenLoaded('item', fn () => $this->uom?->name ?? $this->item->uom?->name),
            'uom_id' => $this->uom_id,
            'uom_factor' => $this->uom_factor,
            // The item's current UOM choices — lets the editor re-populate the line's UOM picker on edit.
            'item_uoms' => $this->whenLoaded('item', fn () => $this->item->relationLoaded('itemUoms') && $this->item->relationLoaded('uom') ? $this->item->uomChoices() : null),
            'qty' => $this->qty,
            'rate' => $this->rate,
            'amount' => $this->amount,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'discount_amount' => $this->discount_amount,
            'net_amount' => $this->net_amount,
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
