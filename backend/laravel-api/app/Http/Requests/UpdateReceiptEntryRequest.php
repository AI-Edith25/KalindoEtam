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

    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', 'required', 'uuid', 'exists:customers,id'],
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
