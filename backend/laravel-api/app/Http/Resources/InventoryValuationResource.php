<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One (item, warehouse) row for the Inventory Valuation report — wraps the plain object InventoryValuationService builds, not an Eloquent model. */
class InventoryValuationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'item_id' => $this->item_id,
            'warehouse_id' => $this->warehouse_id,
            'item_code' => $this->item_code,
            'item_name' => $this->item_name,
            'warehouse_name' => $this->warehouse_name,
            'opening_qty' => $this->opening_qty,
            'opening_value' => $this->opening_value,
            'qty_in' => $this->qty_in,
            'value_in' => $this->value_in,
            'qty_out' => $this->qty_out,
            'value_out' => $this->value_out,
            'closing_qty' => $this->closing_qty,
            'unit_cost' => $this->unit_cost,
            'closing_value' => $this->closing_value,
        ];
    }
}
