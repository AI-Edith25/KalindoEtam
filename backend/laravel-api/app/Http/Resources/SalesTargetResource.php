<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesTargetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sales_person_id' => $this->sales_person_id,
            'sales_person' => new SalesPersonResource($this->whenLoaded('salesPerson')),
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? new BranchResource($this->branch) : null),
            'period_month' => $this->period_month,
            'period_year' => $this->period_year,
            'target_amount' => $this->target_amount,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
