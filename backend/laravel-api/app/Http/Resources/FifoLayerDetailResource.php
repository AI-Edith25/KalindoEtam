<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One layer row for the FIFO Layers report's drill-down — not the full FifoLayer record, just what the ticket's column list asks for. */
class FifoLayerDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'source_document_number' => $this->source_document_number,
            'received_date' => $this->received_date?->format('Y-m-d'),
            'qty_in' => $this->qty_in,
            'qty_remaining' => $this->qty_remaining,
            'unit_cost' => $this->unit_cost,
            'remaining_value' => round((float) $this->qty_remaining * (float) $this->unit_cost, 2),
        ];
    }
}
