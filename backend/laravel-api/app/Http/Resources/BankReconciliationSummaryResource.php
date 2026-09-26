<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankReconciliationSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bank_account_id' => $this->bank_account_id,
            'bank_account_name' => $this->whenLoaded('bankAccount', fn () => $this->bankAccount->name),
            'date' => $this->date->format('Y-m-d'),
            'system_debit_total' => $this->system_debit_total,
            'system_credit_total' => $this->system_credit_total,
            'statement_debit_total' => $this->statement_debit_total,
            'statement_credit_total' => $this->statement_credit_total,
            'variance_debit' => $this->variance_debit,
            'variance_credit' => $this->variance_credit,
            'status' => $this->status,
            'generated_at' => $this->generated_at,
        ];
    }
}
