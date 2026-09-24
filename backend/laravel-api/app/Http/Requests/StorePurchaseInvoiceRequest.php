<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source' => ['sometimes', 'in:goods_receipt,direct'],

            // Goods Receipt-sourced invoice — all selected Goods Receipts must share the same
            // Supplier and be submitted/not-yet-invoiced — enforced in
            // PurchaseInvoiceService::createFromGoodsReceipts() (business logic).
            'goods_receipt_ids' => ['required_if:source,goods_receipt', 'array', 'min:1'],
            'goods_receipt_ids.*' => ['uuid', 'distinct', 'exists:goods_receipts,id'],
            // Manual entry — Goods Receipt items carry no tax snapshot to resolve this from.
            'tax_amount' => ['nullable', 'numeric', 'min:0'],

            // Direct/Non-Stock invoice — no Goods Receipt/PO, lines post straight to an expense
            // account. Account type/active-ness is validated in PurchaseInvoiceService (needs
            // the Item lookup, not available here).
            'supplier_id' => ['required_if:source,direct', 'uuid', 'exists:suppliers,id'],
            'attention' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'items' => ['required_if:source,direct', 'array', 'min:1'],
            'items.*.chart_of_account_id' => ['required_if:source,direct', 'uuid', 'exists:chart_of_accounts,id'],
            'items.*.description' => ['required_if:source,direct', 'string', 'max:255'],
            'items.*.uom' => ['nullable', 'string', 'max:50'],
            'items.*.qty' => ['required_if:source,direct', 'numeric', 'min:0.01'],
            'items.*.rate' => ['required_if:source,direct', 'numeric', 'min:0'],
            'items.*.tax_id' => ['sometimes', 'nullable', 'uuid', 'exists:taxes,id'],

            'invoice_date' => ['required', 'date'],
            // Required for a Goods Receipt-sourced invoice; a Direct invoice auto-derives it
            // from the Supplier's Terms of Payment when omitted (PurchaseInvoiceService::resolveDueDate()).
            'due_date' => ['required_if:source,goods_receipt', 'nullable', 'date', 'after_or_equal:invoice_date'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
