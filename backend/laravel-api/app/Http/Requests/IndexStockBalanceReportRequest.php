<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Deliberately a different class from IndexStockBalanceRequest, which validates the existing bulk explicit-item_ids lookup — this one is the paginated, filterable report. */
class IndexStockBalanceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'warehouse_id' => ['sometimes', 'nullable', 'uuid', 'exists:warehouses,id'],
            'item_group_id' => ['sometimes', 'nullable', 'uuid', 'exists:item_groups,id'],
            'item_id' => ['sometimes', 'nullable', 'uuid', 'exists:items,id'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
