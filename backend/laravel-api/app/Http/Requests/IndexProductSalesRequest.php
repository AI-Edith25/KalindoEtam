<?php

namespace App\Http\Requests;

use App\Enums\DocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Product Sales tab (Sales Report rework) — one row per item, aggregated over invoice_items. */
class IndexProductSalesRequest extends FormRequest
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
            // 'group' — Product Sales' own view toggle, "Detail per item" vs "Ringkas per group".
            'group' => ['sometimes', 'nullable', Rule::in(['item', 'item_group'])],
            'sort' => ['sometimes', 'nullable', Rule::in(['amount', 'qty', 'item_name'])],
            'sort_dir' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // UI only ever offers 25/50/100 but the backend doesn't hard-enforce that allow-list,
            // same convention as every other Index*Request's per_page.
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'format' => ['sometimes', 'nullable', Rule::in(['xlsx', 'csv'])],
        ];
    }
}
