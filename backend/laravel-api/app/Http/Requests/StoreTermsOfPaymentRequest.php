<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTermsOfPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // whereNull('deleted_at') — see StoreChartOfAccountRequest's own comment.
            'code' => ['required', 'string', 'max:255', Rule::unique('terms_of_payments', 'code')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'days' => ['required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
