<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverCommissionRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fleet_id' => $this->fleet_id,
            'item_id' => $this->item_id,
            'item_name' => $this->whenLoaded('item', fn () => $this->item->item_name),
            'rate_per_zak' => $this->rate_per_zak,
            'effective_date' => $this->effective_date->toDateString(),
            'created_at' => $this->created_at,
        ];
    }
}
