<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('item_code')) {
            $this->merge([
                'item_code' => strtoupper($this->item_code),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'item_code' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('items', 'item_code')->ignore($this->route('item'))],
            'item_name' => ['sometimes', 'required', 'string', 'max:255'],
            'item_group_id' => ['sometimes', 'required', 'uuid', 'exists:item_groups,id'],
            'uom_id' => ['sometimes', 'required', 'uuid', 'exists:uoms,id'],
            'standard_rate' => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
