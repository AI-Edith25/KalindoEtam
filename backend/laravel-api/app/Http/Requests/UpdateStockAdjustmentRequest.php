<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['sometimes', 'required', 'uuid', 'exists:warehouses,id'],
            'adjustment_date' => ['sometimes', 'required', 'date'],
            'remarks' => ['nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.item_id' => ['required_with:items', 'uuid', 'exists:items,id'],
            'items.*.counted_qty' => ['required_with:items', 'integer', 'min:0'],
            'items.*.reason' => ['required_with:items', 'string'],
        ];
    }
}
