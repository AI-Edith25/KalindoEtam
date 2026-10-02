<?php

namespace App\Http\Requests;

use App\Enums\DiscountType;
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
            // Locked fields — computed by the system or fixed at creation (Invoice Type decides
            // the Naming Series and is documented in the UI as immutable), never user-editable. A
            // direct API call sending one of these is rejected outright (422) rather than
            // silently ignored, per the ticket's own "reject requests touching locked fields" rule.
            'document_number' => ['prohibited'],
            'invoice_type' => ['prohibited'],
            'delivery_id' => ['prohibited'],
            'sales_order_id' => ['prohibited'],
            'items.*.item_id' => ['prohibited'],
            'items.*.item_code' => ['prohibited'],
            'items.*.item_name' => ['prohibited'],
            'items.*.uom' => ['prohibited'],
            'items.*.amount' => ['prohibited'],
            'subtotal' => ['prohibited'],
            'grand_total' => ['prohibited'],
            'submitted_at' => ['prohibited'],

            'invoice_date' => ['sometimes', 'required', 'date'],
            'due_date' => ['sometimes', 'required', 'date', 'after_or_equal:invoice_date'],
            'terms_of_payment_id' => ['sometimes', 'nullable', 'uuid', 'exists:terms_of_payments,id'],
            // The printed/displayed Location — editable at any status, zero stock/accounting side effects. See Invoice::locationWarehouse().
            'location_warehouse_id' => ['sometimes', 'nullable', 'uuid', 'exists:warehouses,id'],
            'branch_id' => ['sometimes', 'nullable', 'uuid', 'exists:branches,id'],
            // Discount is per-line only now — the header figure is always derived as the sum of
            // the (possibly just-edited) lines' own discount_amount, never a direct input here.
            'discount_type' => ['prohibited'],
            'discount_amount' => ['prohibited'],
            'discount_percentage' => ['prohibited'],
            'tax_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('taxes', 'id')->where('is_active', true)],
            'tax_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
            'sales_person_id' => ['sometimes', 'nullable', 'uuid', 'exists:sales_persons,id'],
            'reference_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reference_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'attention' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tel' => ['sometimes', 'nullable', 'string', 'max:50'],
            'fax' => ['sometimes', 'nullable', 'string', 'max:50'],
            'customer_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            // Required only once this Invoice is Submitted (InvoiceService::updateSubmitted()'s
            // optimistic-lock check) — a Draft-Invoice edit ignores it, unchanged from before.
            'lock_version' => ['sometimes', 'integer', 'min:1'],
            // Only meaningful once Submitted (InvoiceService::applySubmittedItemChanges()) — no
            // add/remove, every id must already belong to this Invoice.
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.id' => [
                'required_with:items', 'uuid',
                Rule::exists('invoice_items', 'id')->where('invoice_id', $this->route('invoice')?->id),
            ],
            'items.*.qty' => ['sometimes', 'numeric', 'min:1'],
            'items.*.rate' => ['sometimes', 'numeric', 'min:0'],
            'items.*.discount_type' => ['sometimes', 'nullable', Rule::enum(DiscountType::class)],
            'items.*.discount_value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'items.*.tax_id' => ['sometimes', 'nullable', 'uuid', 'exists:taxes,id'],
        ];
    }
}
