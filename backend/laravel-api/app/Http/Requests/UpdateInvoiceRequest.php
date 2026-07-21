<?php

namespace App\Http\Requests;

use App\Enums\TaxCalculationMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInvoiceRequest extends FormRequest
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
            'discount_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tax_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('taxes', 'id')->where('is_active', true)],
            'tax_mode' => ['sometimes', 'nullable', Rule::enum(TaxCalculationMode::class)],
            'tax_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
