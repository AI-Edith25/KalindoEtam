<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // whereNull('deleted_at') — see StoreChartOfAccountRequest's own comment.
            'code' => ['required', 'string', 'max:10', Rule::unique('currencies', 'code')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['nullable', 'string', 'max:10'],
            'exchange_rate' => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
