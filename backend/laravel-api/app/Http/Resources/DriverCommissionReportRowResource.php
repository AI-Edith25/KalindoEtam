<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** $this wraps a stdClass row from DriverCommissionReportService::rows(). */
class DriverCommissionReportRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'date' => $this->date,
            'so_number' => $this->so_number,
            'do_number' => $this->do_number,
            'customer' => $this->customer,
            'item_name' => $this->item_name,
            'qty' => $this->qty,
            'plat_number' => $this->plat_number,
            'driver' => $this->driver,
            'delivery_status' => $this->delivery_status,
            'rate_per_zak' => $this->rate_per_zak,
            'total_commission' => $this->total_commission,
        ];
    }
}
