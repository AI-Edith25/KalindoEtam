<?php

namespace App\Http\Requests;

use App\Enums\DiscountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Standalone/direct delivery (no source Sales Order) supplies customer_id and
            // per-line item_id/rate/tax_id directly instead — see DeliveryService::createDirect().
            // One or more Sales Orders may be combined into a single Delivery (mirrors
            // delivery_ids on StoreInvoiceRequest) — all selected Sales Orders must be Approved
            // and belong to the same Customer and Warehouse, enforced in DeliveryService::create()
            // (business logic, not request shape). The older singular sales_order_id is still
            // accepted for backward compatibility (DeliveryService::resolveRequestedSalesOrderIds()).
            'sales_order_id' => ['nullable', 'uuid', 'exists:sales_orders,id'],
            'sales_order_ids' => ['nullable', 'array', 'min:1'],
            'sales_order_ids.*' => ['uuid', 'distinct', 'exists:sales_orders,id'],
            'customer_id' => ['required_without_all:sales_order_id,sales_order_ids', 'nullable', 'uuid', 'exists:customers,id'],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'delivery_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:delivery_date'],
            'terms_of_payment_id' => ['nullable', 'uuid', 'exists:terms_of_payments,id'],
            'remarks' => ['nullable', 'string'],
            'fleet' => ['nullable', 'string', 'max:255'],
            'driver' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sales_order_item_id' => ['required_with:sales_order_id,sales_order_ids', 'nullable', 'uuid', 'exists:sales_order_items,id'],
            'items.*.item_id' => ['required_without_all:sales_order_id,sales_order_ids', 'nullable', 'uuid', 'exists:items,id'],
            'items.*.rate' => ['required_without_all:sales_order_id,sales_order_ids', 'nullable', 'numeric', 'min:0'],
            'items.*.qty' => ['required', 'numeric', 'min:0.01'],
            // Only meaningful for a Direct Delivery line (no sales_order_id) — SO-linked items
            // always get their tax copied from the linked sales_order_items row instead, see
            // DeliveryService::create(). Optional and manual, no default.
            'items.*.tax_id' => ['sometimes', 'nullable', 'uuid', 'exists:taxes,id'],
            // Same posture as tax_id above — only meaningful for a Direct Delivery line.
            // SO-linked items always derive their discount from the linked sales_order_items
            // row instead (DeliveryService's own allocation rule), never accept one directly.
            'items.*.discount_type' => ['sometimes', 'nullable', Rule::enum(DiscountType::class)],
            'items.*.discount_value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }
}
