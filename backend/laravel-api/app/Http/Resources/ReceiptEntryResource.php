<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceiptEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'status' => $this->status,
            'revision' => $this->revision,
            'customer_id' => $this->customer_id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'receipt_date' => $this->receipt_date?->format('Y-m-d'),
            'payment_method' => $this->payment_method,
            'reference_number' => $this->reference_number,
            'remarks' => $this->remarks,
            'total_amount' => $this->total_amount,
            'allocated_amount' => $this->allocated_amount,
            'unallocated_amount' => $this->unallocatedAmount(),
            'items' => ReceiptEntryItemResource::collection($this->whenLoaded('items')),
            'submitted_at' => $this->submitted_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
        ];
    }
}
