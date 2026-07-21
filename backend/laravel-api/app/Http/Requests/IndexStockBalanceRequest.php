<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexStockBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['uuid', 'exists:items,id'],
        ];
    }
}
