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
            // Deliberately no 'exists:' on these (unlike item_group_id below) -- an invalid id
            // must be silently ignored (it simply matches no row via whereIn), not fail the whole
            // request with a 422. See AccountsReceivableRepository's own normalizedSalesPersonIds().
            'customer_ids' => ['sometimes', 'nullable', 'array'],
            'customer_ids.*' => ['string'],
            'item_ids' => ['sometimes', 'nullable', 'array'],
            'item_ids.*' => ['string'],
            'item_group_id' => ['sometimes', 'nullable', 'uuid', 'exists:item_groups,id'],
            'sales_person_ids' => ['sometimes', 'nullable', 'array'],
            'sales_person_ids.*' => ['string'],
            'branch_ids' => ['sometimes', 'nullable', 'array'],
            'branch_ids.*' => ['string'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'sort' => ['sometimes', 'nullable', Rule::in(['amount', 'qty', 'customer_name', 'transaction_count'])],
            'sort_dir' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'format' => ['sometimes', 'nullable', Rule::in(['xlsx', 'csv'])],
        ];
    }
}
