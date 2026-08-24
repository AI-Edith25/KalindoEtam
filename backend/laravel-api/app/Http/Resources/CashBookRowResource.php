<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One Cash Book Transaction row — wraps the stdClass row CashBookRepository's raw query returns. */
class CashBookRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'document_number' => $this->document_number,
            'party_name' => $this->party_name,
            'payment_method_name' => $this->payment_method_name,
            'date' => $this->date,
            'debit' => (float) $this->debit,
            'credit' => (float) $this->credit,
            'status' => $this->status,
            'branch_id' => $this->branch_id,
            'unallocated' => (float) $this->unallocated,
            'reference_number' => $this->reference_number,
        ];
    }
}
