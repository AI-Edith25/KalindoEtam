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
            'current_stock' => $this->current_stock,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
