<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One PO Tracking row — wraps the stdClass row PoTrackingRepository's filtered/aggregated query returns. */
class PoTrackingRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_date' => $this->order_date,
            'document_number' => $this->document_number,
            'supplier_name' => $this->supplier_name,
            'total_amount' => (float) $this->total_amount,
            'ordered_qty' => (float) $this->ordered_qty,
            'received_qty' => (float) $this->received_qty,
            'remaining_qty' => (float) $this->remaining_qty,
            'fulfillment_pct' => (float) $this->fulfillment_pct,
            'receiving_status' => $this->receiving_status,
            'is_overdue' => (bool) $this->is_overdue,
        ];
    }
}
