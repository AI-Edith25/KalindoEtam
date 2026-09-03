<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkUpdateItemWarehousePriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cells' => ['required', 'array', 'min:1', 'max:500'],
            'cells.*.item_id' => ['required', 'uuid', 'exists:items,id'],
            'cells.*.warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'cells.*.rate' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
