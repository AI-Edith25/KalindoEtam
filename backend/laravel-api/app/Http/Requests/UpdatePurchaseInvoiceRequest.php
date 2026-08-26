<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_date' => ['sometimes', 'required', 'date'],
            'due_date' => ['sometimes', 'required', 'date', 'after_or_equal:invoice_date'],
            'tax_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'reference_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
