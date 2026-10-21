<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Gross Profit report (split out of Sales Report's old Margin tab) — profit/margin over validated Sales Invoice lines net of Credit Notes, grouped by item/customer/invoice. */
class IndexGrossProfitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Deliberately no 'exists:' on these -- an invalid id must be silently ignored (it
            // simply matches no row via whereIn), not fail the whole request with a 422. See
            // AccountsReceivableRepository's own normalizedSalesPersonIds(). Sales Report's own
            // IndexProductSalesRequest etc. switched from single-id to these multi-select arrays
            // first -- Gross Profit reuses the same SalesReportFiltersBar/MultiSelectFilter, so it
            // follows suit to keep sending the same shape.
            'customer_ids' => ['sometimes', 'nullable', 'array'],
            'customer_ids.*' => ['string'],
            'item_ids' => ['sometimes', 'nullable', 'array'],
            'item_ids.*' => ['string'],
            'sales_person_ids' => ['sometimes', 'nullable', 'array'],
            'sales_person_ids.*' => ['string'],
            'branch_ids' => ['sometimes', 'nullable', 'array'],
            'branch_ids.*' => ['string'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'group' => ['sometimes', 'nullable', Rule::in(['item', 'customer', 'invoice'])],
            'sort' => ['sometimes', 'nullable', Rule::in(['amount', 'cost_amount', 'profit', 'margin_pct', 'qty', 'item_name', 'customer_name', 'date', 'document_number'])],
            'sort_dir' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'format' => ['sometimes', 'nullable', Rule::in(['xlsx', 'csv'])],
        ];
    }
}
