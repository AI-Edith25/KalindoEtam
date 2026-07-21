<?php

namespace App\Http\Resources;

use App\Enums\AccountsReceivableStatus;
use App\Enums\DocumentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $accountsReceivable = $this->relationLoaded('accountsReceivable') ? $this->accountsReceivable : null;
        $paidAmount = $accountsReceivable ? (float) $accountsReceivable->paid_amount : 0.0;
        $creditedAmount = $accountsReceivable ? (float) $accountsReceivable->credited_amount : 0.0;
        $debitedAmount = $accountsReceivable ? (float) $accountsReceivable->debited_amount : 0.0;

        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'status' => $this->status,
            'display_status' => $this->resolveDisplayStatus(),
            'revision' => $this->revision,
            'delivery_id' => $this->delivery_id,
            'delivery' => $this->whenLoaded('delivery', fn () => [
                'id' => $this->delivery->id,
                'document_number' => $this->delivery->document_number,
            ]),
            'sales_order_id' => $this->sales_order_id,
            'customer_id' => $this->customer_id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'invoice_date' => $this->invoice_date?->format('Y-m-d'),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'tax_id' => $this->tax_id,
            'tax' => $this->whenLoaded('tax', fn () => $this->tax ? new TaxResource($this->tax) : null),
            'tax_amount' => $this->tax_amount,
            'grand_total' => $this->grand_total,
            'paid_amount' => $paidAmount,
            'outstanding_amount' => (float) $this->grand_total - $paidAmount,
            'credited_amount' => $creditedAmount,
            'debited_amount' => $debitedAmount,
            // Includes debited_amount — a Debit Note raises what's left to credit, since it
            // grows the same accounts_receivable.amount a Credit Note's own ceiling reads live.
            // See docs/DEBIT_NOTE_DESIGN.md §7 "Interaction with Credit Notes".
            'creditable_amount' => (float) $this->grand_total - $creditedAmount + $debitedAmount,
            'remarks' => $this->remarks,
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'payment_history' => $accountsReceivable
                ? $accountsReceivable->receiptEntryItems->map(fn ($line) => [
                    'id' => $line->id,
                    'received_amount' => $line->allocated_amount,
                    'receipt_entry_id' => $line->receipt_entry_id,
                    'receipt_entry_document_number' => $line->receiptEntry->document_number,
                    'receipt_date' => $line->receiptEntry->receipt_date?->format('Y-m-d'),
                    'payment_method' => $line->receiptEntry->payment_method,
                ])
                : [],
            'credit_note_history' => $this->relationLoaded('creditNotes')
                ? $this->creditNotes->map(fn ($creditNote) => [
                    'id' => $creditNote->id,
                    'document_number' => $creditNote->document_number,
                    'credit_note_date' => $creditNote->credit_note_date?->format('Y-m-d'),
                    'reason' => $creditNote->reason,
                    'total_amount' => $creditNote->total_amount,
                    'status' => $creditNote->status,
                    'is_reversed' => $creditNote->is_reversed,
                ])
                : [],
            'debit_note_history' => $this->relationLoaded('debitNotes')
                ? $this->debitNotes->map(fn ($debitNote) => [
                    'id' => $debitNote->id,
                    'document_number' => $debitNote->document_number,
                    'debit_note_date' => $debitNote->debit_note_date?->format('Y-m-d'),
                    'reason' => $debitNote->reason,
                    'total_amount' => $debitNote->total_amount,
                    'status' => $debitNote->status,
                    'is_reversed' => $debitNote->is_reversed,
                ])
                : [],
            'submitted_at' => $this->submitted_at,
            'cancelled_at' => $this->cancelled_at,
            'created_at' => $this->created_at,
        ];
    }

    /** Draft/Submitted/Partially Paid/Paid/Cancelled, derived — never stored. */
    protected function resolveDisplayStatus(): string
    {
        if ($this->status === DocumentStatus::CANCELLED) {
            return 'cancelled';
        }

        if ($this->status === DocumentStatus::DRAFT) {
            return 'draft';
        }

        $arStatus = $this->relationLoaded('accountsReceivable') ? $this->accountsReceivable?->status : null;

        return match ($arStatus) {
            AccountsReceivableStatus::PARTIALLY_PAID => 'partially_paid',
            AccountsReceivableStatus::PAID => 'paid',
            default => 'submitted',
        };
    }
}
