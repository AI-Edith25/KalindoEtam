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
            // Nullable, not required — a Direct invoice re-derives it from the Supplier's Terms
            // of Payment when omitted (PurchaseInvoiceService::resolveDueDate()). A Goods
            // Receipt-sourced invoice keeps sending it explicitly (frontend always has it).
            'due_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:invoice_date'],
            'tax_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'reference_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],

            // Direct/Non-Stock invoice only — see PurchaseInvoiceService::updateDirect(). The
            // source itself is immutable post-create, not accepted here.
            'supplier_id' => ['sometimes', 'uuid', 'exists:suppliers,id'],
            'attention' => ['sometimes', 'nullable', 'string', 'max:255'],
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.chart_of_account_id' => ['required_with:items', 'uuid', 'exists:chart_of_accounts,id'],
            'items.*.description' => ['required_with:items', 'string', 'max:255'],
            'items.*.uom' => ['nullable', 'string', 'max:50'],
            'items.*.qty' => ['required_with:items', 'numeric', 'min:0.01'],
            'items.*.rate' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.tax_id' => ['sometimes', 'nullable', 'uuid', 'exists:taxes,id'],
        ];
    }
}
