<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentEntryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'accounts_payable_id' => $this->accounts_payable_id,
            'accounts_payable' => new AccountsPayableResource($this->whenLoaded('accountsPayable')),
            'paid_amount' => $this->paid_amount,
        ];
    }
}
