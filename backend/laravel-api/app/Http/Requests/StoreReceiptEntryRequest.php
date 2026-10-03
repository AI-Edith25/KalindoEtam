<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Enums\ReceiptEntryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReceiptEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_type' => ['required', Rule::enum(ReceiptEntryType::class)],
            'customer_id' => ['required_if:payment_type,customer', 'nullable', 'uuid', 'exists:customers,id'],
            'income_account_id' => ['required_if:payment_type,other_income', 'nullable', 'uuid', 'exists:chart_of_accounts,id'],
            'description' => ['required_if:payment_type,other_income', 'nullable', 'string', 'max:255'],
            'receipt_date' => ['required', 'date'],
            'cash_account_id' => ['required', 'uuid', Rule::exists('chart_of_accounts', 'id')->where('is_cash_bank', true)],
            'branch_id' => ['nullable', 'uuid', 'exists:branches,id'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
            'total_amount' => ['required', 'numeric', 'gt:0'],
            // D2 (UAT review 2026-08-12): Giro/Cek number+due date required only when
            // payment_method is giro/cheque — every other payment_method ignores them.
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'giro_number' => ['required_if:payment_method,giro,cheque', 'nullable', 'string', 'max:255'],
            'giro_due_date' => ['required_if:payment_method,giro,cheque', 'nullable', 'date'],
        ];
    }
}
