<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFleetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // whereNull('deleted_at') — see StoreChartOfAccountRequest's own comment.
            'plat_number' => ['required', 'string', 'max:20', Rule::unique('fleets', 'plat_number')->whereNull('deleted_at')],
            'default_driver_name' => ['nullable', 'string', 'max:255'],
            'location_id' => ['nullable', 'uuid', 'exists:warehouses,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
