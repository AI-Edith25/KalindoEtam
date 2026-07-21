<?php

namespace App\Http\Requests;

use App\Enums\TaxCalculationMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'uuid', 'exists:suppliers,id'],
            'order_date' => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'remarks' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'uuid', 'exists:items,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.rate' => ['required', 'numeric', 'min:0'],
            // Same Tax Engine contract as Invoice — docs/TAX_ENGINE_DESIGN.md §6.
            'tax_id' => ['nullable', 'uuid', Rule::exists('taxes', 'id')->where('is_active', true)],
            'tax_mode' => ['sometimes', 'nullable', Rule::enum(TaxCalculationMode::class)],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
