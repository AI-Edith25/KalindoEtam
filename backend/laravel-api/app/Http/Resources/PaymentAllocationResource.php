<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentAllocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_entry_id' => $this->receipt_entry_id,
            'accounts_receivable_id' => $this->accounts_receivable_id,
            'accounts_receivable' => new AccountsReceivableResource($this->whenLoaded('accountsReceivable')),
            'allocated_amount' => $this->allocated_amount,
            'allocation_date' => $this->allocation_date?->format('Y-m-d'),
            'is_reversed' => $this->is_reversed,
            'created_at' => $this->created_at,
        ];
    }
}
