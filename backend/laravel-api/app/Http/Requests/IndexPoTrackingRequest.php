<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Purchase Report's PO Tracking tab — submitted Purchase Orders whose Goods Receipts haven't fully arrived yet. */
class IndexPoTrackingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['sometimes', 'nullable', 'uuid', 'exists:suppliers,id'],
            'warehouse_id' => ['sometimes', 'nullable', 'uuid', 'exists:warehouses,id'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'receiving_status' => ['sometimes', 'nullable', Rule::in(['not_received', 'partial', 'complete'])],
            // Not Laravel's 'boolean' rule — axios serializes a JS boolean query param as the
            // literal string "true"/"false", which that rule's strict in_array() check rejects
            // (only true/false/0/1/"0"/"1" pass), 422ing every request. See
            // PoTrackingRepository::filteredQuery() for the matching filter_var() cast.
            'incomplete_only' => ['sometimes', 'nullable', Rule::in(['0', '1', 'true', 'false'])],
            'sort' => ['sometimes', 'nullable', Rule::in(['order_date', 'total_amount', 'ordered_qty', 'received_qty', 'remaining_qty', 'fulfillment_pct', 'document_number'])],
            'sort_dir' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
            'format' => ['sometimes', 'nullable', Rule::in(['xlsx', 'csv'])],
        ];
    }
}
