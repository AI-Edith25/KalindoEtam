<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_date' => ['sometimes', 'required', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:invoice_date'],
            'tax_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'reference_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],

            // Only takes effect while the invoice has no stock (Goods Receipt-sourced) lines —
            // PurchaseInvoiceService::update() otherwise leaves supplier_id untouched.
            'supplier_id' => ['sometimes', 'uuid', 'exists:suppliers,id'],
            'attention' => ['sometimes', 'nullable', 'string', 'max:255'],
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Full replace of this invoice's non-stock lines only — stock lines are immutable
            // post-create and untouched by this key. Omit entirely to leave existing non-stock
            // lines as they are.
            'items' => ['sometimes', 'array'],
            'items.*.chart_of_account_id' => ['required_with:items', 'uuid', 'exists:chart_of_accounts,id'],
            'items.*.description' => ['required_with:items', 'string', 'max:255'],
            'items.*.uom' => ['nullable', 'string', 'max:50'],
            'items.*.qty' => ['required_with:items', 'numeric', 'min:0.01'],
            'items.*.rate' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.tax_id' => ['sometimes', 'nullable', 'uuid', 'exists:taxes,id'],

            // Rate overrides for existing stock lines — see Store rule's own comment.
            'item_rates' => ['sometimes', 'array'],
            'item_rates.*.goods_receipt_item_id' => ['required', 'uuid', 'exists:goods_receipt_items,id'],
            'item_rates.*.rate' => ['required', 'numeric', 'min:0'],
        ];
    }
}
