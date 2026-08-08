<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * payment_type is deliberately not accepted here — it's immutable after
     * create() (PaymentEntryService::update() branches on the existing
     * record's own type, never a request value), so a draft can't be
     * switched between Supplier and General Expense mid-edit.
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['sometimes', 'required', 'uuid', 'exists:suppliers,id'],
            'expense_account_id' => ['sometimes', 'nullable', 'uuid', 'exists:chart_of_accounts,id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'payment_date' => ['sometimes', 'required', 'date'],
            'cash_account_id' => ['sometimes', 'required', 'uuid', Rule::exists('chart_of_accounts', 'id')->where('is_cash_bank', true)],
            'branch_id' => ['sometimes', 'nullable', 'uuid', 'exists:branches,id'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.accounts_payable_id' => ['required_with:items', 'uuid', 'exists:accounts_payables,id'],
            'items.*.paid_amount' => ['required_with:items', 'numeric', 'gt:0'],
        ];
    }
}
