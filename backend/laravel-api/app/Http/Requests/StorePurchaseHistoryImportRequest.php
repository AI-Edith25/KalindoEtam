<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Each Purchase Report tab now has its own Import button locked to one file type — `expected_type`
 * is that button's promise, checked against the file's actually-detected type in
 * PurchaseHistoryImportController::store() (auto-detect still runs; a mismatch is rejected rather
 * than silently processed under the "wrong" button).
 *
 * warehouse_id/placeholder_item_id are only real missing input for the 2 types that fabricate a
 * placeholder line (Supplier Purchase Listing / Purchase Order Tracking) — Product Purchase Report
 * creates no document at all, so neither applies to it.
 */
class StorePurchaseHistoryImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt,xlsx,xls'],
            'expected_type' => ['required', Rule::in(['supplier_purchase_listing', 'product_purchase_report', 'purchase_order_tracking'])],
            'warehouse_id' => ['required_if:expected_type,purchase_order_tracking', 'nullable', 'uuid', 'exists:warehouses,id'],
            'placeholder_item_id' => ['required_if:expected_type,supplier_purchase_listing,purchase_order_tracking', 'nullable', 'uuid', 'exists:items,id'],
        ];
    }
}
