<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'status' => $this->status,
            'revision' => $this->revision,
            'source_warehouse_id' => $this->source_warehouse_id,
            'source_warehouse' => new WarehouseResource($this->whenLoaded('sourceWarehouse')),
            'destination_warehouse_id' => $this->destination_warehouse_id,
            'destination_warehouse' => new WarehouseResource($this->whenLoaded('destinationWarehouse')),
            'transfer_date' => $this->transfer_date?->format('Y-m-d'),
            'remarks' => $this->remarks,
            'items' => StockTransferItemResource::collection($this->whenLoaded('items')),
            'submitted_at' => $this->submitted_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
        ];
    }
}
