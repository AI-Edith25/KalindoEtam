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
            'sales_person_id' => ['sometimes', 'nullable', 'uuid', 'exists:sales_persons,id'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
