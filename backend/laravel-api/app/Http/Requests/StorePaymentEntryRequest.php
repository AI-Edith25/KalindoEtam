<?php

namespace App\Http\Requests;

use App\Enums\PaymentEntryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_type' => ['required', Rule::enum(PaymentEntryType::class)],
            'supplier_id' => ['required_if:payment_type,supplier', 'nullable', 'uuid', 'exists:suppliers,id'],
            'expense_account_id' => ['required_if:payment_type,general_expense', 'nullable', 'uuid', 'exists:chart_of_accounts,id'],
            'description' => ['required_if:payment_type,general_expense', 'nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['required', 'date'],
            'cash_account_id' => ['required', 'uuid', Rule::exists('chart_of_accounts', 'id')->where('is_cash_bank', true)],
            'branch_id' => ['nullable', 'uuid', 'exists:branches,id'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
