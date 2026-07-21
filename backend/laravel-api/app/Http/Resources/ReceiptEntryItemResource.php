<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceiptEntryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'accounts_receivable_id' => $this->accounts_receivable_id,
            'accounts_receivable' => new AccountsReceivableResource($this->whenLoaded('accountsReceivable')),
            'received_amount' => $this->allocated_amount,
            'allocation_date' => $this->allocation_date?->format('Y-m-d'),
            'is_reversed' => $this->is_reversed,
        ];
    }
}
