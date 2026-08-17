<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['sometimes', 'required', 'uuid', 'exists:warehouses,id'],
            'delivery_date' => ['sometimes', 'required', 'date'],
            'due_date' => ['sometimes', 'required', 'date', 'after_or_equal:delivery_date'],
            'terms_of_payment_id' => ['nullable', 'uuid', 'exists:terms_of_payments,id'],
            'remarks' => ['nullable', 'string'],
            'fleet' => ['nullable', 'string', 'max:255'],
            'driver' => ['nullable', 'string', 'max:255'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.sales_order_item_id' => ['required_with:items', 'uuid', 'exists:sales_order_items,id'],
            'items.*.qty' => ['required_with:items', 'integer', 'min:1'],
        ];
    }
}
