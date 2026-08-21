<?php

namespace App\Http\Requests;

use App\Enums\DiscountType;
use App\Enums\InvoiceType;
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
            // Goods only — Transportation carries no Sales Order/Delivery at all. All selected
            // Deliveries must share the same Customer and be delivered/not-yet-invoiced —
            // enforced in InvoiceService::create() (business logic, not request shape).
            'delivery_ids' => ['required_if:invoice_type,goods', 'nullable', 'array', 'min:1'],
            'delivery_ids.*' => ['uuid', 'distinct', 'exists:deliveries,id'],
            // Transportation only — picked directly instead of being derived from a Delivery.
            'customer_id' => ['required_if:invoice_type,transportation', 'nullable', 'uuid', 'exists:customers,id'],
            // Transportation only — no Sales Order to derive Branch from, so it's captured directly here.
            'branch_id' => ['required_if:invoice_type,transportation', 'nullable', 'uuid', 'exists:branches,id'],
            // Transportation only — manual, freestanding lines (no Item/inventory link).
            'items' => ['required_if:invoice_type,transportation', 'nullable', 'array', 'min:1'],
            'items.*.description' => ['required', 'string'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.rate' => ['required', 'numeric', 'min:0'],
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
            // Fallback only — ignored once tax_id resolves to a real Tax (InvoiceService::create()).
            // Kept so a document with no applicable Tax record can still carry a manual figure,
            // the same behavior this field already had before the Tax Engine existed.
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
            'sales_person_id' => ['nullable', 'uuid', 'exists:sales_persons,id'],
            'reference_1' => ['nullable', 'string', 'max:255'],
            'reference_2' => ['nullable', 'string', 'max:255'],
        ];
    }
}
