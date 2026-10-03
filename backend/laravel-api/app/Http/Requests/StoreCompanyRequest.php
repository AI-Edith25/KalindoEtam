<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // whereNull('deleted_at') — see StoreChartOfAccountRequest's own comment.
            'code' => ['required', 'string', 'max:255', Rule::unique('companies', 'code')->whereNull('deleted_at')],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'npwp' => ['nullable', 'string', 'max:255'],
            'currency' => ['sometimes', 'string', 'max:10'],
            'timezone' => ['sometimes', 'string', 'max:255'],
            'fiscal_year_start' => ['required', 'date'],
        ];
    }
}
