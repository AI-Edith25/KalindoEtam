<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Shared by both PPN Keluaran (customer_id/branch_id) and PPN Masukan (supplier_id/warehouse_id) — each endpoint only reads the fields relevant to it. */
class IndexTaxReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'tax_id' => ['sometimes', 'nullable', 'uuid', 'exists:taxes,id'],
            'customer_id' => ['sometimes', 'nullable', 'uuid', 'exists:customers,id'],
            'branch_id' => ['sometimes', 'nullable', 'uuid', 'exists:branches,id'],
            'supplier_id' => ['sometimes', 'nullable', 'uuid', 'exists:suppliers,id'],
            'warehouse_id' => ['sometimes', 'nullable', 'uuid', 'exists:warehouses,id'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
