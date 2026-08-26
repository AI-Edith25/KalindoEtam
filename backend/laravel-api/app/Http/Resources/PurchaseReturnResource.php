<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'status' => $this->status,
            'revision' => $this->revision,
            'purchase_invoice_id' => $this->purchase_invoice_id,
            'purchase_invoice' => $this->whenLoaded('purchaseInvoice', fn () => [
                'id' => $this->purchaseInvoice->id,
                'document_number' => $this->purchaseInvoice->document_number,
                'grand_total' => $this->purchaseInvoice->grand_total,
            ]),
            'supplier_id' => $this->supplier_id,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'return_date' => $this->return_date?->format('Y-m-d'),
            'reason' => $this->reason,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'remarks' => $this->remarks,
            'is_reversed' => $this->is_reversed,
            'reversed_at' => $this->reversed_at,
            'items' => PurchaseReturnItemResource::collection($this->whenLoaded('items')),
            'submitted_at' => $this->submitted_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
        ];
    }
}
