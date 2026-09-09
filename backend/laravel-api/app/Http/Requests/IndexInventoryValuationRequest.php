<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexInventoryValuationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'warehouse_id' => ['sometimes', 'nullable', 'uuid', 'exists:warehouses,id'],
            'item_group_id' => ['sometimes', 'nullable', 'uuid', 'exists:item_groups,id'],
            'item_id' => ['sometimes', 'nullable', 'uuid', 'exists:items,id'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
