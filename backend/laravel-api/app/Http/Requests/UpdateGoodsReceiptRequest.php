<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['sometimes', 'required', 'uuid', 'exists:warehouses,id'],
            'receipt_date' => ['sometimes', 'required', 'date'],
            'due_date' => ['sometimes', 'required', 'date', 'after_or_equal:receipt_date'],
            'remarks' => ['nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            // purchase_order_id is immutable post-create (not accepted here) — whether a line
            // needs purchase_order_item_id vs item_id/rate is resolved server-side from the
            // existing Goods Receipt's own purchase_order_id, not from this request's input.
            'items.*.purchase_order_item_id' => ['nullable', 'uuid', 'exists:purchase_order_items,id'],
            'items.*.item_id' => ['nullable', 'uuid', 'exists:items,id'],
            'items.*.rate' => ['nullable', 'numeric', 'min:0'],
            'items.*.qty' => ['required_with:items', 'integer', 'min:1'],
            'items.*.actual_weight' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'items.*.weight_unit' => ['nullable', 'in:kg,ton'],
            'items.*.weighbridge_ref' => ['nullable', 'string', 'max:64'],
        ];
    }
}
