<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFleetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plat_number' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('fleets', 'plat_number')->ignore($this->route('fleet'))],
            'default_driver_name' => ['nullable', 'string', 'max:255'],
            'location_id' => ['nullable', 'uuid', 'exists:warehouses,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
