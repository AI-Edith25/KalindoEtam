<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sales Listing tab (Sales Report rework) — one row per Invoice or Credit Note document (grain is
 * document-level, not item-level, so no item/item_group filter here — see SalesListingRepository).
 */
class IndexSalesListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Deliberately no 'exists:' here -- an invalid id must be silently ignored (it simply
            // matches no row via whereIn), not fail the whole request with a 422. See
            // AccountsReceivableRepository's own normalizedSalesPersonIds().
            'customer_ids' => ['sometimes', 'nullable', 'array'],
            'customer_ids.*' => ['string'],
            'sales_person_ids' => ['sometimes', 'nullable', 'array'],
            'sales_person_ids.*' => ['string'],
            'branch_ids' => ['sometimes', 'nullable', 'array'],
            'branch_ids.*' => ['string'],
            'type' => ['sometimes', 'nullable', Rule::in(['invoice', 'credit_note'])],
            'payment_status' => ['sometimes', 'nullable', Rule::in(['unpaid', 'partially_paid', 'paid'])],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'sort' => ['sometimes', 'nullable', Rule::in(['date', 'document_number', 'customer_name', 'amount_incl_tax'])],
            'sort_dir' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'format' => ['sometimes', 'nullable', Rule::in(['xlsx', 'csv'])],
        ];
    }
}
