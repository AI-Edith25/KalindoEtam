<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOpeningStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'cutoff_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'uuid', 'exists:items,id'],
            // Whole-number-vs-decimal enforcement happens in OpeningStockService via
            // QtyCategoryValidator (needs the Item loaded, not available here).
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ];
    }
}
