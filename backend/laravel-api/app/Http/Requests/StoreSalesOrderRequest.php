<?php

namespace App\Http\Requests;

use App\Enums\DiscountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid', 'exists:customers,id'],
            'sales_person_id' => ['nullable', 'uuid', 'exists:sales_persons,id'],
            'branch_id' => ['required', 'uuid', 'exists:branches,id'],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'order_date' => ['required', 'date'],
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'uuid', 'exists:items,id'],
            // Base UOM or one of the item's extra UOMs — checked (factor resolved) in SalesOrderService. Null = base.
            'items.*.uom_id' => ['nullable', 'uuid', 'exists:uoms,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.rate' => ['required', 'numeric', 'min:0'],
            'items.*.discount_type' => ['nullable', Rule::enum(DiscountType::class)],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_id' => ['nullable', 'uuid', 'exists:taxes,id'],
        ];
    }
}
