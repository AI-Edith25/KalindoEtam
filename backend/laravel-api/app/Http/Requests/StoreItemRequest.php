<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'item_code' => strtoupper($this->item_code),
        ]);
    }

    public function rules(): array
    {
        return [
            'item_code' => ['required', 'string', 'max:255', 'unique:items,item_code'],
            'item_name' => ['required', 'string', 'max:255'],
            'item_group_id' => ['required', 'uuid', 'exists:item_groups,id'],
            'uom_id' => ['required', 'uuid', 'exists:uoms,id'],
            'standard_rate' => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
