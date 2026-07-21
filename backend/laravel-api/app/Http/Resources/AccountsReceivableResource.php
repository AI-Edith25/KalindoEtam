<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountsReceivableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'invoice_id' => $this->invoice_id,
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id' => $this->invoice->id,
                'document_number' => $this->invoice->document_number,
                'invoice_date' => $this->invoice->invoice_date?->format('Y-m-d'),
                'status' => $this->invoice->status,
            ] : null),
            'sales_order_id' => $this->sales_order_id,
            'delivery_id' => $this->delivery_id,
            'delivery' => $this->whenLoaded('delivery', fn () => $this->delivery ? [
                'id' => $this->delivery->id,
                'document_number' => $this->delivery->document_number,
            ] : null),
            'reference_number' => $this->reference_number,
            'amount' => $this->amount,
            'paid_amount' => $this->paid_amount,
            'outstanding_amount' => $this->amount - $this->paid_amount,
            'due_date' => $this->due_date?->format('Y-m-d'),
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
