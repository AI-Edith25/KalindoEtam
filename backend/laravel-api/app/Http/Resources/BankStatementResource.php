<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankStatementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'format_template' => $this->format_template,
            'bank_account_id' => $this->bank_account_id,
            'bank_account_name' => $this->bankAccount?->name,
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'original_filename' => $this->original_filename,
            'status' => $this->status,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at,
        ];
    }
}
