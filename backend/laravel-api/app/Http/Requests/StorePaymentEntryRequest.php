<?php

namespace App\Http\Requests;

use App\Enums\PaymentEntryType;
use App\Enums\PaymentMethod;
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
            'amount' => ['required_if:payment_type,general_expense', 'nullable', 'numeric', 'gt:0'],
            'payment_date' => ['required', 'date'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
            'items' => ['required_if:payment_type,supplier', 'nullable', 'array', 'min:1'],
            'items.*.accounts_payable_id' => ['required_with:items', 'uuid', 'exists:accounts_payables,id'],
            'items.*.paid_amount' => ['required_with:items', 'numeric', 'gt:0'],
        ];
    }
}
