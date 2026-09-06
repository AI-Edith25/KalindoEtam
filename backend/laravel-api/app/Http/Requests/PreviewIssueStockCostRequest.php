<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreviewIssueStockCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'uuid', 'exists:items,id'],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'qty' => ['required', 'numeric', 'min:0.0001'],
        ];
    }
}
