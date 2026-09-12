<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentEntryExpenseLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_entry_id' => $this->payment_entry_id,
            'line_no' => $this->line_no,
            'expense_account_id' => $this->expense_account_id,
            'expense_account' => new ChartOfAccountResource($this->whenLoaded('expenseAccount')),
            'description' => $this->description,
            'branch_id' => $this->branch_id,
            'amount' => $this->amount,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
