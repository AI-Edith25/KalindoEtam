<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Closing History's one row per period — closed_by/closed_at/reopened_by/reopened_at hold the latest event only; full history lives in DocumentTimeline. See docs/PERIOD_CLOSING_DESIGN.md §5. */
class AccountingPeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fiscal_year_id' => $this->fiscal_year_id,
            'fiscal_year_name' => $this->whenLoaded('fiscalYear', fn () => $this->fiscalYear->name),
            'name' => $this->name,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'status' => $this->status,
            'closed_by' => $this->whenLoaded('closedBy', fn () => $this->closedBy?->name),
            'closed_at' => $this->closed_at,
            'reopened_by' => $this->whenLoaded('reopenedBy', fn () => $this->reopenedBy?->name),
            'reopened_at' => $this->reopened_at,
        ];
    }
}
