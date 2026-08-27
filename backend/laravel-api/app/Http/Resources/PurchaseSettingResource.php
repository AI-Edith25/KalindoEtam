<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'weight_over_receipt_tolerance_percent' => $this->weight_over_receipt_tolerance_percent === null
                ? null
                : (float) $this->weight_over_receipt_tolerance_percent,
            'updated_at' => $this->updated_at,
        ];
    }
}
