<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'status' => $this->status,
            'revision' => $this->revision,
            'sales_order_id' => $this->sales_order_id,
            'customer_id' => $this->customer_id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'delivery_date' => $this->delivery_date?->format('Y-m-d'),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'remarks' => $this->remarks,
            'items' => DeliveryItemResource::collection($this->whenLoaded('items')),
            'is_invoiced' => $this->whenLoaded('invoice', fn () => $this->invoice !== null),
            'submitted_at' => $this->submitted_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
        ];
    }
}
