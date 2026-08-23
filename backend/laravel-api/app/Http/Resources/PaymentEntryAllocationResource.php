<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentEntryAllocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_entry_id' => $this->payment_entry_id,
            'accounts_payable_id' => $this->accounts_payable_id,
            'accounts_payable' => new AccountsPayableResource($this->whenLoaded('accountsPayable')),
            'allocated_amount' => $this->allocated_amount,
            'allocation_date' => $this->allocation_date?->format('Y-m-d'),
            'is_reversed' => $this->is_reversed,
            'created_at' => $this->created_at,
        ];
    }
}
