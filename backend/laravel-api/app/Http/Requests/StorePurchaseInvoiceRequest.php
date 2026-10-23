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
            // Supplier is always required up front now — both the Goods Receipt list (Step 2 of
            // the editor) and the non-stock Account dropdown filter against it. `source` is no
            // longer accepted from the client — PurchaseInvoiceService::create() derives
            // goods_receipt/direct/mixed from whichever of goods_receipt_ids/items is present.
            'supplier_id' => ['required', 'uuid', 'exists:suppliers,id'],

            // Goods Receipt-sourced (stock) lines — optional. All selected Goods Receipts must
            // belong to the chosen Supplier, be submitted, and not yet invoiced — enforced in
            // PurchaseInvoiceService::buildStockLines() (business logic).
            'goods_receipt_ids' => ['sometimes', 'array'],
            'goods_receipt_ids.*' => ['uuid', 'distinct', 'exists:goods_receipts,id'],
            // Rate overrides for stock lines — Qty/Item still come from the Goods Receipt
            // untouched, only Rate is user-editable. A separate key from `items` below, which is
            // the non-stock line shape — the two never overlap.
            'item_rates' => ['sometimes', 'array'],
            'item_rates.*.goods_receipt_item_id' => ['required', 'uuid', 'exists:goods_receipt_items,id'],
            'item_rates.*.rate' => ['required', 'numeric', 'min:0'],
            // Manual header tax figure for the stock portion only — Goods Receipt items carry no
            // tax snapshot to resolve this from. Non-stock lines carry their own tax_id instead
            // (summed server-side).
            'tax_amount' => ['nullable', 'numeric', 'min:0'],

            // Non-stock lines — optional. Each posts straight to a Chart-of-Accounts Expense or
            // Liability account. Account type/active-ness is validated in PurchaseInvoiceService
            // (needs the Chart of Account lookup, not available here).
            'items' => ['sometimes', 'array'],
            'items.*.chart_of_account_id' => ['required_with:items', 'uuid', 'exists:chart_of_accounts,id'],
            'items.*.description' => ['required_with:items', 'string', 'max:255'],
            'items.*.uom' => ['nullable', 'string', 'max:50'],
            'items.*.qty' => ['required_with:items', 'numeric', 'min:0.01'],
            'items.*.rate' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.tax_id' => ['sometimes', 'nullable', 'uuid', 'exists:taxes,id'],

            'invoice_date' => ['required', 'date'],
            // Nullable — defaults to the Supplier's Terms of Payment when omitted
            // (PurchaseInvoiceService::resolveDueDate()).
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'attention' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
