<?php

namespace App\Http\Requests;

use App\Enums\TaxCalculationMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delivery_id' => ['required', 'uuid', 'exists:deliveries,id'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:invoice_date'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            // Only an Active tax may be selected for a new document — docs/TAX_ENGINE_DESIGN.md §9 (Tax Status).
            'tax_id' => ['nullable', 'uuid', Rule::exists('taxes', 'id')->where('is_active', true)],
            'tax_mode' => ['sometimes', 'nullable', Rule::enum(TaxCalculationMode::class)],
            // Fallback only — ignored once tax_id resolves to a real Tax (InvoiceService::create()).
            // Kept so a document with no applicable Tax record can still carry a manual figure,
            // the same behavior this field already had before the Tax Engine existed.
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
