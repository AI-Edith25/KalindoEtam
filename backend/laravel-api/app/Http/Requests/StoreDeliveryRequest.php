<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Direct/no-Sales-Order creation was removed 2026-10-08 — a new Delivery must always
            // come from one or more Sales Orders (DeliveryService::create() now rejects an empty
            // resolveRequestedSalesOrderIds() outright instead of routing to the removed
            // createDirect()). An existing direct Delivery from before this change is still
            // editable (DeliveryService::update()/updateComplete() still branch on
            // sales_order_id === null for that), just not creatable anew.
            //
            // One or more Sales Orders may be combined into a single Delivery (mirrors
            // delivery_ids on StoreInvoiceRequest) — all selected Sales Orders must be Approved
            // and belong to the same Customer and Warehouse, enforced in DeliveryService::create()
            // (business logic, not request shape). The older singular sales_order_id is still
            // accepted for backward compatibility (DeliveryService::resolveRequestedSalesOrderIds()).
            'sales_order_id' => ['required_without:sales_order_ids', 'nullable', 'uuid', 'exists:sales_orders,id'],
            'sales_order_ids' => ['required_without:sales_order_id', 'nullable', 'array', 'min:1'],
            'sales_order_ids.*' => ['uuid', 'distinct', 'exists:sales_orders,id'],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'delivery_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:delivery_date'],
            'terms_of_payment_id' => ['nullable', 'uuid', 'exists:terms_of_payments,id'],
            'remarks' => ['nullable', 'string'],
            'fleet' => ['nullable', 'string', 'max:255'],
            'driver' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sales_order_item_id' => ['required', 'uuid', 'exists:sales_order_items,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
