<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSalesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', 'required', 'uuid', 'exists:customers,id'],
            'sales_person_id' => ['nullable', 'uuid', 'exists:sales_persons,id'],
            'branch_id' => ['sometimes', 'required', 'uuid', 'exists:branches,id'],
            'warehouse_id' => ['sometimes', 'required', 'uuid', 'exists:warehouses,id'],
            'order_date' => ['sometimes', 'required', 'date'],
            'expected_delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'remarks' => ['nullable', 'string'],
            'attention' => ['nullable', 'string', 'max:255'],
            'tel' => ['nullable', 'string', 'max:50'],
            'fax' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:255'],
            'terms_of_payment_id' => ['nullable', 'uuid', 'exists:terms_of_payments,id'],
            'tax_id' => ['nullable', 'uuid', 'exists:taxes,id'],
            'override_credit_block' => ['sometimes', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:500'],
            'override_stock_block' => ['sometimes', 'boolean'],
            'stock_override_reason' => ['nullable', 'string', 'max:500'],
            'items' => ['sometimes', 'array', 'min:1'],
            // Only meaningful once the order is Approved (SalesOrderService::syncApprovedItems)
            // — a Submitted-order edit still fully replaces every line and ignores this. Scoped
            // to this order so a stray id can't be used to touch another Sales Order's line.
            'items.*.id' => [
                'nullable', 'uuid',
                Rule::exists('sales_order_items', 'id')->where('sales_order_id', $this->route('salesOrder')?->id),
            ],
            'items.*.item_id' => ['required_with:items', 'uuid', 'exists:items,id'],
            'items.*.qty' => ['required_with:items', 'integer', 'min:1'],
            'items.*.rate' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.tax_id' => ['nullable', 'uuid', 'exists:taxes,id'],
        ];
    }
}
