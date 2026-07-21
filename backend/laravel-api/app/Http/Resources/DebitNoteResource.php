<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DebitNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'status' => $this->status,
            'revision' => $this->revision,
            'invoice_id' => $this->invoice_id,
            'invoice' => $this->whenLoaded('invoice', fn () => [
                'id' => $this->invoice->id,
                'document_number' => $this->invoice->document_number,
                'grand_total' => $this->invoice->grand_total,
            ]),
            'customer_id' => $this->customer_id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'debit_note_date' => $this->debit_note_date?->format('Y-m-d'),
            'reason' => $this->reason,
            'subtotal_goods' => $this->subtotal_goods,
            'subtotal_other' => $this->subtotal_other,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'remarks' => $this->remarks,
            'is_reversed' => $this->is_reversed,
            'reversed_at' => $this->reversed_at,
            'items' => DebitNoteItemResource::collection($this->whenLoaded('items')),
            'submitted_at' => $this->submitted_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
        ];
    }
}
