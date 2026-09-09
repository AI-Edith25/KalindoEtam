<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LedgerAccountsReceivableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid', 'exists:customers,id'],
            'invoice_date_from' => ['sometimes', 'nullable', 'date'],
            'invoice_date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:invoice_date_from'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
