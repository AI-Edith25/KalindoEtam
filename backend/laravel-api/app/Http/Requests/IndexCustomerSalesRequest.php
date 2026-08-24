<?php

namespace App\Http\Requests;

use App\Enums\DocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Customer Sales tab (Sales Report rework) — one row per customer, aggregated over invoice_items. */
class IndexCustomerSalesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', Rule::enum(DocumentStatus::class)],
            'customer_id' => ['sometimes', 'nullable', 'uuid', 'exists:customers,id'],
            'item_id' => ['sometimes', 'nullable', 'uuid', 'exists:items,id'],
            'item_group_id' => ['sometimes', 'nullable', 'uuid', 'exists:item_groups,id'],
            'sales_person_id' => ['sometimes', 'nullable', 'uuid', 'exists:sales_persons,id'],
            'branch_id' => ['sometimes', 'nullable', 'uuid', 'exists:branches,id'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'sort' => ['sometimes', 'nullable', Rule::in(['amount', 'qty', 'customer_name', 'transaction_count'])],
            'sort_dir' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
            'format' => ['sometimes', 'nullable', Rule::in(['xlsx', 'csv'])],
        ];
    }
}
