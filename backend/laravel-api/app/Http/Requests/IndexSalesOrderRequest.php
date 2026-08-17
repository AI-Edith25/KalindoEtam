<?php

namespace App\Http\Requests;

use App\Enums\SalesOrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexSalesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', Rule::enum(SalesOrderStatus::class)],
            'customer_id' => ['sometimes', 'nullable', 'uuid', 'exists:customers,id'],
            'sales_person_id' => ['sometimes', 'nullable', 'uuid', 'exists:sales_persons,id'],
            'branch_id' => ['sometimes', 'nullable', 'uuid', 'exists:branches,id'],
            'item_id' => ['sometimes', 'nullable', 'uuid', 'exists:items,id'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'outstanding' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
