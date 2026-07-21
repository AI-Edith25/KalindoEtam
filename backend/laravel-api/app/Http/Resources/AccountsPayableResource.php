<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountsPayableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'purchase_order_id' => $this->purchase_order_id,
            'goods_receipt_id' => $this->goods_receipt_id,
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
