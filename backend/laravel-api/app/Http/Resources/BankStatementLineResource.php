<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankStatementLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_date' => $this->transaction_date->format('Y-m-d'),
            'description' => $this->description,
            'debit_amount' => $this->debit_amount,
            'credit_amount' => $this->credit_amount,
            'running_balance' => $this->running_balance,
            'match_status' => $this->match_status,
            'matched_document' => $this->whenLoaded('matchedDocument', fn () => $this->matchedDocument === null ? null : [
                'type' => $this->matched_document_type,
                'id' => $this->matched_document_id,
                'document_number' => $this->matchedDocument->document_number,
            ]),
        ];
    }
}
