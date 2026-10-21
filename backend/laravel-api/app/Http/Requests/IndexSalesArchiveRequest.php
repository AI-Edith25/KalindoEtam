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
            // Arrays, matching the live Sales Report endpoints' own multi-select filters -- but
            // this snapshot data has no relational columns to whereIn() against, so the service
            // only ever resolves the first selected id (see ProductSalesArchiveService/
            // SalesListingArchiveService's own baseLines()).
            'customer_ids' => ['sometimes', 'nullable', 'array'],
            'customer_ids.*' => ['string'],
            'item_ids' => ['sometimes', 'nullable', 'array'],
            'item_ids.*' => ['string'],
            'item_group_id' => ['sometimes', 'nullable', 'uuid', 'exists:item_groups,id'],
            'sales_person_ids' => ['sometimes', 'nullable', 'array'],
            'sales_person_ids.*' => ['string'],
            'branch_ids' => ['sometimes', 'nullable', 'array'],
            'branch_ids.*' => ['string'],
            'status' => ['sometimes', 'nullable', 'string'],
            'type' => ['sometimes', 'nullable', Rule::in(['invoice', 'credit_note'])],
            'payment_status' => ['sometimes', 'nullable', Rule::in(['unpaid', 'partially_paid', 'paid'])],
            'group' => ['sometimes', 'nullable', Rule::in(['item', 'item_group'])],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'format' => ['sometimes', 'nullable', Rule::in(['xlsx', 'csv'])],
        ];
    }
}
