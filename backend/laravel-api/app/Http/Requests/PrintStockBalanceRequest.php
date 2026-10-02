<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PrintStockBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'warehouse_id' => ['sometimes', 'nullable', 'uuid', 'exists:warehouses,id'],
            'item_group_id' => ['sometimes', 'nullable', 'uuid', 'exists:item_groups,id'],
            'item_id' => ['sometimes', 'nullable', 'uuid', 'exists:items,id'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
        ];
    }
}
