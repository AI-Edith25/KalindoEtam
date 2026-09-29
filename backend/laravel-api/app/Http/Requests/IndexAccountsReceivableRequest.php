<?php

namespace App\Http\Requests;

use App\Enums\AccountsReceivableStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAccountsReceivableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::enum(AccountsReceivableStatus::class)],
            'customer_id' => ['sometimes', 'nullable', 'uuid', 'exists:customers,id'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'invoice_date_from' => ['sometimes', 'nullable', 'date'],
            'invoice_date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:invoice_date_from'],
            'aging_bucket' => ['sometimes', 'nullable', Rule::in(['30', '45', '60', '90', 'over_180'])],
            'branch_id' => ['sometimes', 'nullable', 'uuid', 'exists:branches,id'],
            // Legacy single-id param — kept for backward compatibility with any existing caller/
            // bookmark. New callers send sales_person_ids (below) instead.
            'sales_person_id' => ['sometimes', 'nullable', 'uuid', 'exists:sales_persons,id'],
            // Deliberately no 'exists:sales_persons,id' here (unlike the singular field above) —
            // an invalid id must be silently ignored (it simply matches no row via whereIn), not
            // fail the whole request with a 422. See AccountsReceivableRepository::filteredQuery().
            'sales_person_ids' => ['sometimes', 'nullable', 'array'],
            'sales_person_ids.*' => ['string'],
            'invoice_ids' => ['sometimes', 'nullable', 'array'],
            'invoice_ids.*' => ['uuid', 'exists:invoices,id'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
