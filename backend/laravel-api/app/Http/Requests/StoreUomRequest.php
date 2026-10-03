<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // whereNull('deleted_at') — see StoreChartOfAccountRequest's own comment.
            'name' => ['required', 'string', 'max:255', Rule::unique('uoms', 'name')->whereNull('deleted_at')],
            'symbol' => ['nullable', 'string', 'max:50'],
        ];
    }
}
