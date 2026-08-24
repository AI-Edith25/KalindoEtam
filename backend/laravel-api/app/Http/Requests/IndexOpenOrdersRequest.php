<?php

namespace App\Http\Requests;

use App\Enums\SalesOrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Open Orders tab (Sales Report rework) — one row per Sales Order line still outstanding
 * (qty_ordered > qty_invoiced). Cancelled Sales Orders are always excluded regardless of the
 * `status` filter — see OpenOrdersRepository.
 */
class IndexOpenOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Cancelled is never a valid choice here — Open Orders excludes it unconditionally.
            'status' => ['sometimes', 'nullable', Rule::in([SalesOrderStatus::SUBMITTED->value, SalesOrderStatus::APPROVED->value])],
            'customer_id' => ['sometimes', 'nullable', 'uuid', 'exists:customers,id'],
            'item_id' => ['sometimes', 'nullable', 'uuid', 'exists:items,id'],
            'item_group_id' => ['sometimes', 'nullable', 'uuid', 'exists:item_groups,id'],
            'sales_person_id' => ['sometimes', 'nullable', 'uuid', 'exists:sales_persons,id'],
            'branch_id' => ['sometimes', 'nullable', 'uuid', 'exists:branches,id'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'aging' => ['sometimes', 'nullable', Rule::in(['0-7', '8-30', '31-60', 'over_60'])],
            'overdue_only' => ['sometimes', 'nullable'],
            'sort' => ['sometimes', 'nullable', Rule::in(['order_date', 'document_number', 'customer_name', 'item_name', 'qty_outstanding', 'outstanding_value'])],
            'sort_dir' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
            'format' => ['sometimes', 'nullable', Rule::in(['xlsx', 'csv'])],
        ];
    }
}
