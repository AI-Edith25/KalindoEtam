<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sales_order_item_id' => $this->sales_order_item_id,
            'item_id' => $this->item_id,
            'item_code' => $this->item_code,
            'item_name' => $this->item_name,
            // Live from the Item master (not a line snapshot) — reflects the item's current
            // flag even for an old Delivery, since this only gates the edit screen's own UI.
            'driver_commission_eligible' => $this->whenLoaded('item', fn () => (bool) $this->item->driver_commission_eligible, false),
            'uom' => $this->uom,
            'rate' => $this->rate,
            'qty' => $this->qty,
            'amount' => $this->amount,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'discount_amount' => $this->discount_amount,
            'net_amount' => $this->net_amount,
            'tax_id' => $this->tax_id,
            'tax' => new TaxResource($this->whenLoaded('tax')),
            'tax_amount' => $this->tax_amount,
            // Locks this row against removal on the edit screen — see
            // DeliveryService::updateComplete()'s own guard, which is authoritative; this is
            // display-only so the UI doesn't have to guess and get a 422 back.
            'is_invoiced' => $this->whenLoaded('invoiceItem', fn () => $this->invoiceItem !== null, false),
        ];
    }
}
