<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReceiptEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * payment_type is deliberately not accepted here — it's immutable after create()
     * (ReceiptEntryService::update() doesn't branch on it, the record's own type never changes),
     * same convention as UpdatePaymentEntryRequest. A draft can't be switched between Customer
     * and Other Income mid-edit.
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', 'nullable', 'uuid', 'exists:customers,id'],
            'income_account_id' => ['sometimes', 'nullable', 'uuid', 'exists:chart_of_accounts,id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'receipt_date' => ['sometimes', 'required', 'date'],
            'cash_account_id' => ['sometimes', 'required', 'uuid', Rule::exists('chart_of_accounts', 'id')->where('is_cash_bank', true)],
            'branch_id' => ['sometimes', 'nullable', 'uuid', 'exists:branches,id'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
            'total_amount' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'payment_method' => ['sometimes', 'required', Rule::enum(PaymentMethod::class)],
            'giro_number' => ['required_if:payment_method,giro,cheque', 'nullable', 'string', 'max:255'],
            'giro_due_date' => ['required_if:payment_method,giro,cheque', 'nullable', 'date'],
        ];
    }
}
