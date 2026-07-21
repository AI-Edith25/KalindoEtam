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
            'sales_order_id' => ['required', 'uuid', 'exists:sales_orders,id'],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'delivery_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:delivery_date'],
            'remarks' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sales_order_item_id' => ['required', 'uuid', 'exists:sales_order_items,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
        ];
    }
}
