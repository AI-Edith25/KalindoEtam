<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Standalone/direct receipt (no source Purchase Order) supplies supplier_id
            // and per-line item_id/rate directly instead — see GoodsReceiptService::createDirect().
            'purchase_order_id' => ['nullable', 'uuid', 'exists:purchase_orders,id'],
            'supplier_id' => ['required_without:purchase_order_id', 'nullable', 'uuid', 'exists:suppliers,id'],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'receipt_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:receipt_date'],
            'remarks' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required_with:purchase_order_id', 'nullable', 'uuid', 'exists:purchase_order_items,id'],
            'items.*.item_id' => ['required_without:purchase_order_id', 'nullable', 'uuid', 'exists:items,id'],
            'items.*.rate' => ['required_without:purchase_order_id', 'nullable', 'numeric', 'min:0'],
            'items.*.qty' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }
}
