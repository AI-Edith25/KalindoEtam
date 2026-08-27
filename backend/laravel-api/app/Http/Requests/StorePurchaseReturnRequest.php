<?php

namespace App\Http\Requests;

use App\Enums\PurchaseReturnReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_invoice_id' => ['required', 'uuid', 'exists:purchase_invoices,id'],
            'return_date' => ['required', 'date'],
            'reason' => ['required', Rule::enum(PurchaseReturnReason::class)],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
            'items' => ['sometimes', 'array'],
            'items.*.purchase_invoice_item_id' => ['required_with:items', 'uuid', 'exists:purchase_invoice_items,id'],
            // Whole-number-vs-decimal enforcement happens in PurchaseReturnService via
            // QtyCategoryValidator (needs the Item loaded, not available here).
            'items.*.qty_returned' => ['sometimes', 'numeric', 'min:0'],
            'items.*.amount' => ['required_with:items', 'numeric', 'min:0'],
        ];
    }
}
