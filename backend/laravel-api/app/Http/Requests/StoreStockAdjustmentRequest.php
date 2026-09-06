<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'adjustment_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'uuid', 'exists:items,id'],
            // Whole-number-vs-decimal enforcement happens in StockAdjustmentService via
            // QtyCategoryValidator (needs the Item loaded, not available here).
            'items.*.counted_qty' => ['required', 'numeric', 'min:0'],
            // Required only when counted_qty exceeds the system's current balance (a new FIFO
            // layer is created for the found qty) — that comparison needs the Item's live
            // ledger balance, not available here, so it's enforced in
            // StockAdjustmentService::replaceItems() instead.
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.reason' => ['required', 'string'],
        ];
    }
}
