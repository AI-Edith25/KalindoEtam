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
            'sales_order' => new SalesOrderResource($this->whenLoaded('salesOrder')),
            'customer_id' => $this->customer_id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'delivery_date' => $this->delivery_date?->format('Y-m-d'),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'terms_of_payment_id' => $this->terms_of_payment_id,
            'terms_of_payment' => new TermsOfPaymentResource($this->whenLoaded('termsOfPayment')),
            'remarks' => $this->remarks,
            'items' => DeliveryItemResource::collection($this->whenLoaded('items')),
            'amount' => $this->whenLoaded('items', fn () => $this->items->sum('amount')),
            'is_invoiced' => $this->whenLoaded('invoices', fn () => $this->invoices->isNotEmpty()),
            'submitted_at' => $this->submitted_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
        ];
    }
}
