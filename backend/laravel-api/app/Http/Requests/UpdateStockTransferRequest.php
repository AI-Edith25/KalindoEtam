<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_warehouse_id' => ['sometimes', 'required', 'uuid', 'exists:warehouses,id'],
            'destination_warehouse_id' => ['sometimes', 'required', 'uuid', 'exists:warehouses,id', 'different:source_warehouse_id'],
            'transfer_date' => ['sometimes', 'required', 'date'],
            'remarks' => ['nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.item_id' => ['required_with:items', 'uuid', 'exists:items,id'],
            'items.*.qty' => ['required_with:items', 'numeric', 'min:0.01'],
        ];
    }

    public function messages(): array
    {
        return [
            'destination_warehouse_id.different' => 'Destination warehouse must be different from the source warehouse.',
        ];
    }
}
