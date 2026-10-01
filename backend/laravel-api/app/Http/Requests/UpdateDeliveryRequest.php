<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Locked fields — computed by the system or fixed at creation, never user-editable.
            // A direct API call sending one of these is rejected outright (422) rather than
            // silently ignored, per the ticket's own "reject requests touching locked fields" rule.
            'document_number' => ['prohibited'],
            // Direct/SO-linked mode is fixed at creation (not accepted here) — whether a line needs
            // sales_order_item_id vs item_id/rate is resolved server-side from the existing
            // Delivery's own sales_order_id, not from this request's shape. Same approach as
            // UpdateGoodsReceiptRequest.
            'sales_order_id' => ['prohibited'],
            'items.*.item_code' => ['prohibited'],
            'items.*.item_name' => ['prohibited'],
            'items.*.uom' => ['prohibited'],
            'items.*.amount' => ['prohibited'],
            'subtotal' => ['prohibited'],
            'tax_amount' => ['prohibited'],
            'grand_total' => ['prohibited'],
            'submitted_at' => ['prohibited'],

            'customer_id' => ['sometimes', 'required', 'uuid', 'exists:customers,id'],
            'warehouse_id' => ['sometimes', 'required', 'uuid', 'exists:warehouses,id'],
            'sales_person_id' => ['sometimes', 'nullable', 'uuid', 'exists:sales_persons,id'],
            'delivery_date' => ['sometimes', 'required', 'date'],
            'due_date' => ['sometimes', 'required', 'date', 'after_or_equal:delivery_date'],
            'terms_of_payment_id' => ['sometimes', 'nullable', 'uuid', 'exists:terms_of_payments,id'],
            'attention' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tel' => ['sometimes', 'nullable', 'string', 'max:50'],
            'fax' => ['sometimes', 'nullable', 'string', 'max:50'],
            'remarks' => ['nullable', 'string'],
            'fleet' => ['nullable', 'string', 'max:255'],
            'driver' => ['nullable', 'string', 'max:255'],
            // Required only once this Delivery is Complete (DeliveryService::updateComplete()'s
            // optimistic-lock check) — a Pending-Delivery edit ignores it (that path has no
            // concurrent-edit story of its own yet, unchanged from before this ticket).
            'lock_version' => ['sometimes', 'integer', 'min:1'],
            'items' => ['sometimes', 'array', 'min:1'],
            // Only meaningful once the Delivery is Complete (DeliveryService::updateComplete()) —
            // a Pending-Delivery edit still fully replaces every line and ignores this. Scoped to
            // this Delivery so a stray id can't be used to touch another Delivery's line.
            'items.*.id' => [
                'nullable', 'uuid',
                Rule::exists('delivery_items', 'id')->where('delivery_id', $this->route('delivery')?->id),
            ],
            'items.*.sales_order_item_id' => ['nullable', 'uuid', 'exists:sales_order_items,id'],
            'items.*.item_id' => ['nullable', 'uuid', 'exists:items,id'],
            'items.*.qty' => ['required_with:items', 'numeric', 'min:0.01'],
            'items.*.rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'items.*.tax_id' => ['sometimes', 'nullable', 'uuid', 'exists:taxes,id'],
        ];
    }
}
