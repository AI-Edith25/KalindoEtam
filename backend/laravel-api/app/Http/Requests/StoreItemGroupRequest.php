<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // whereNull('deleted_at') — see StoreChartOfAccountRequest's own comment.
            'name' => ['required', 'string', 'max:255', Rule::unique('item_groups', 'name')->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
