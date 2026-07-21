<?php

namespace App\Http\Requests;

use App\Enums\TaxCalculationMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['sometimes', 'required', 'uuid', 'exists:suppliers,id'],
            'order_date' => ['sometimes', 'required', 'date'],
            'expected_delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'remarks' => ['nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.item_id' => ['required_with:items', 'uuid', 'exists:items,id'],
            'items.*.qty' => ['required_with:items', 'integer', 'min:1'],
            'items.*.rate' => ['required_with:items', 'numeric', 'min:0'],
            'tax_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('taxes', 'id')->where('is_active', true)],
            'tax_mode' => ['sometimes', 'nullable', Rule::enum(TaxCalculationMode::class)],
            'tax_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }
}
