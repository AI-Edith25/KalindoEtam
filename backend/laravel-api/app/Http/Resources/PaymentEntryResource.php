<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'status' => $this->status,
            'revision' => $this->revision,
            'payment_type' => $this->payment_type,
            'supplier_id' => $this->supplier_id,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'expense_account_id' => $this->expense_account_id,
            'expense_account' => new ChartOfAccountResource($this->whenLoaded('expenseAccount')),
            'description' => $this->description,
            'payment_date' => $this->payment_date?->format('Y-m-d'),
            'cash_account_id' => $this->cash_account_id,
            'cash_account' => new ChartOfAccountResource($this->whenLoaded('cashAccount')),
            'branch_id' => $this->branch_id,
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'reference_number' => $this->reference_number,
            'remarks' => $this->remarks,
            'total_amount' => $this->total_amount,
            'allocated_amount' => $this->allocated_amount,
            'unallocated_amount' => $this->unallocatedAmount(),
            'items' => PaymentEntryAllocationResource::collection($this->whenLoaded('items')),
            'submitted_at' => $this->submitted_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
        ];
    }
}
