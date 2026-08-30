<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One Purchase Journal row — wraps the stdClass row PurchaseJournalRepository's query builder returns. */
class PurchaseJournalRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'document_number' => $this->document_number,
            'date' => $this->date,
            'reference_number' => $this->reference_number,
            'supplier_code' => $this->supplier_code,
            'supplier_name' => $this->supplier_name,
            'amount' => (float) $this->amount,
            'tax' => (float) $this->tax,
            'amount_incl_tax' => (float) $this->amount_incl_tax,
        ];
    }
}
