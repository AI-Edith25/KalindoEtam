<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Purchase Journal's Purchase Invoice/Purchase Return sub-tabs. No branch_id filter — Purchase has no Branch concept anywhere in the schema, unlike Sales. */
class IndexPurchaseJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'view' => ['sometimes', 'nullable', Rule::in(['purchase_invoice', 'purchase_return'])],
            'format' => ['sometimes', 'nullable', Rule::in(['xlsx', 'csv'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'supplier_id' => ['sometimes', 'nullable', 'uuid', 'exists:suppliers,id'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
        ];
    }
}
