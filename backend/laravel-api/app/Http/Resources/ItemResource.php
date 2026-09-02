<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_code' => $this->item_code,
            'item_name' => $this->item_name,
            'item_group_id' => $this->item_group_id,
            'item_group' => new ItemGroupResource($this->whenLoaded('itemGroup')),
            'uom_id' => $this->uom_id,
            'uom' => new UomResource($this->whenLoaded('uom')),
            'standard_rate' => $this->standard_rate,
            // Only present when the request asked for a price_zone_id (see ItemController::index)
            // — the eager-loaded, zone-filtered itemPrices collection has at most one row.
            'effective_rate' => $this->whenLoaded('itemPrices', fn () => $this->itemPrices->first()->rate ?? $this->standard_rate, $this->standard_rate),
            'current_stock' => $this->current_stock,
            'allow_over_receipt' => $this->allow_over_receipt,
            'qty_category' => $this->qty_category,
            'purchase_tax_id' => $this->purchase_tax_id,
            'purchase_tax' => new TaxResource($this->whenLoaded('purchaseTax')),
            'sales_tax_id' => $this->sales_tax_id,
            'sales_tax' => new TaxResource($this->whenLoaded('salesTax')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
