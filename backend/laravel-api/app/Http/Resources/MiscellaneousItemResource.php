<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MiscellaneousItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'misc_code' => $this->misc_code,
            'description' => $this->description,
            'rate' => $this->rate,
            'uom_id' => $this->uom_id,
            'uom' => new UomResource($this->whenLoaded('uom')),
            'charge_type' => $this->charge_type,
            'unit_cost' => $this->unit_cost,
            'sales_account_id' => $this->sales_account_id,
            'sales_account' => new ChartOfAccountResource($this->whenLoaded('salesAccount')),
            'purchase_account_id' => $this->purchase_account_id,
            'purchase_account' => new ChartOfAccountResource($this->whenLoaded('purchaseAccount')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
