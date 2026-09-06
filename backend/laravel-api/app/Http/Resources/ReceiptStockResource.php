<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceiptStockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'status' => $this->status,
            'revision' => $this->revision,
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'receipt_date' => $this->receipt_date?->format('Y-m-d'),
            'remarks' => $this->remarks,
            'items' => ReceiptStockItemResource::collection($this->whenLoaded('items')),
            'line_count' => $this->whenLoaded('items', fn () => $this->items->count()),
            'total_value' => $this->whenLoaded('items', fn () => round((float) $this->items->sum('amount'), 2)),
            'submitted_at' => $this->submitted_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
        ];
    }
}
