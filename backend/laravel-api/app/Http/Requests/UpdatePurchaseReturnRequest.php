<?php

namespace App\Http\Requests;

use App\Enums\PurchaseReturnReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'return_date' => ['sometimes', 'required', 'date'],
            'reason' => ['sometimes', 'required', Rule::enum(PurchaseReturnReason::class)],
            'tax_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
            'items' => ['sometimes', 'array'],
            'items.*.purchase_invoice_item_id' => ['required_with:items', 'uuid', 'exists:purchase_invoice_items,id'],
            'items.*.qty_returned' => ['sometimes', 'numeric', 'min:0'],
            'items.*.amount' => ['required_with:items', 'numeric', 'min:0'],
        ];
    }
}
