<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'order_date' => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'remarks' => ['nullable', 'string'],
            'override_credit_block' => ['sometimes', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'uuid', 'exists:items,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.rate' => ['required', 'numeric', 'min:0'],
        ];
    }
}
