<?php

namespace App\Http\Requests;

use App\Enums\DiscountType;
use App\Enums\InvoiceType;
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
            // All selected Deliveries must share the same Customer and be delivered/not-yet-invoiced —
            // enforced in InvoiceService::create() (business logic, not request shape).
            'delivery_ids' => ['required', 'array', 'min:1'],
            'delivery_ids.*' => ['uuid', 'distinct', 'exists:deliveries,id'],
            // Drives which Naming Series generates document_number — see Invoice::documentType().
            'invoice_type' => ['required', Rule::enum(InvoiceType::class)],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:invoice_date'],
            'terms_of_payment_id' => ['nullable', 'uuid', 'exists:terms_of_payments,id'],
            // Type decides which of the next two fields InvoiceService::resolveDiscount() reads —
            // 'amount cannot exceed subtotal' is enforced there, since subtotal isn't known yet here.
            'discount_type' => ['nullable', Rule::enum(DiscountType::class)],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
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
