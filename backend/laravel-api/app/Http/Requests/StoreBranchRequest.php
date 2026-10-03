<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:255'],
            // whereNull('deleted_at') — see StoreChartOfAccountRequest's own comment.
            'code' => ['required', 'string', 'max:255', Rule::unique('branches', 'code')->whereNull('deleted_at')],
            'address' => ['nullable', 'string', 'max:255'],
            'is_head_office' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
