<?php

namespace App\Http\Requests;

use App\Enums\CreditNoteReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_id' => ['required', 'uuid', 'exists:invoices,id'],
            'credit_note_date' => ['required', 'date'],
            'reason' => ['required', Rule::enum(CreditNoteReason::class)],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
            'items' => ['sometimes', 'array'],
            'items.*.invoice_item_id' => ['required_with:items', 'uuid', 'exists:invoice_items,id'],
            'items.*.qty_credited' => ['sometimes', 'integer', 'min:0'],
            'items.*.amount' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.restock' => ['sometimes', 'boolean'],
        ];
    }
}
