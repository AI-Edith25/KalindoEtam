<?php

namespace App\Http\Requests;

use App\Enums\DebitNoteReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDebitNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_id' => ['required', 'uuid', 'exists:invoices,id'],
            'debit_note_date' => ['required', 'date'],
            'reason' => ['required', Rule::enum(DebitNoteReason::class)],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
            'items' => ['sometimes', 'array'],
            'items.*.invoice_item_id' => ['sometimes', 'nullable', 'uuid', 'exists:invoice_items,id'],
            'items.*.description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'items.*.qty_adjusted' => ['sometimes', 'integer', 'min:0'],
            'items.*.rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'items.*.amount' => ['required_with:items', 'numeric', 'min:0.01'],
        ];
    }
}
