<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Shared filter validation for all 3 Sales Archive read endpoints (listing/customer/product) -- each controller action reads only the fields relevant to it. */
class IndexSalesArchiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_id' => ['sometimes', 'nullable', 'uuid', 'exists:customers,id'],
            'item_id' => ['sometimes', 'nullable', 'uuid', 'exists:items,id'],
            'item_group_id' => ['sometimes', 'nullable', 'uuid', 'exists:item_groups,id'],
            'sales_person_id' => ['sometimes', 'nullable', 'uuid'],
            'branch_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', 'nullable', 'string'],
            'type' => ['sometimes', 'nullable', Rule::in(['invoice', 'credit_note'])],
            'payment_status' => ['sometimes', 'nullable', Rule::in(['unpaid', 'partially_paid', 'paid'])],
            'group' => ['sometimes', 'nullable', Rule::in(['item', 'item_group'])],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
            'format' => ['sometimes', 'nullable', Rule::in(['xlsx', 'csv'])],
        ];
    }
}
